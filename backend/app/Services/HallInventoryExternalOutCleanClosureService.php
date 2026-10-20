<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** External_out custody-only CLEAN closeout. Each originally owned unit must
 * return physically GOOD in one or more witnessed, separately owner-reviewed,
 * hash-verified partner receipt batches. Never issues a financial claim. */
final class HallInventoryExternalOutCleanClosureService {
    public function __construct(private readonly TenantAccess $access) {}
    private function gate():void {
        if(!config('inventory_controls.loan_drafts_enabled')||
            !config('inventory_controls.external_out_dispatch_enabled')||
            !config('inventory_controls.external_out_return_enabled')||
            !config('inventory_controls.external_out_clean_closure_enabled'))
            throw new ConflictHttpException('Outside owned clean return closure not released.');
    }
    private function verify(object $row):void {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->evidence_storage_path))
            throw new ConflictHttpException('Original outside partner signed custody document missing.');
        $bytes=$disk->get((string)$row->evidence_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
           !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Original outside partner signed document tampered.');
    }
    public function close(User $closer,string $companyId,string $loanId,string $closureId):array {
        $this->gate();
        foreach([$companyId,$loanId,$closureId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact owned outside loan and closure UUIDs required.');
        return DB::transaction(function()use($closer,$companyId,$loanId,$closureId):array {
            // Deterministic company-first lock and company-local immutable receipt series.
            $company=DB::table('companies')->where('id',$companyId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$company)throw new AuthorizationException('Outside-owned loan company is inactive.');
            $loan=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$loanId)
                ->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_out')
                throw new ConflictHttpException('Only outside lending owned source loans can close in EOL series.');
            $hallId=(string)$loan->lending_hall_id;
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$closer->getKey()||
                !$this->access->canConfigureHall($closer,$hall)||
                (int)$loan->created_by===(int)$closer->getKey())
                throw new AuthorizationException('An independent current lending-hall owner/admin is required.');
            $old=DB::table('hall_inventory_external_out_clean_closures')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old) {
                if((string)$old->id!==$closureId||
                    (int)$old->closed_by!==(int)$closer->getKey())
                    throw new ConflictHttpException('Loan already has an immutable external owned clean closure.');
                $this->pdfBytes($old);
                return $this->summary($old);
            }
            // A reported incident, even if later denied, requires explicit
            // case disposition instead of accidentally bypassing loss review.
            if(DB::table('hall_inventory_loan_incident_reports')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->exists())
                throw new ConflictHttpException('Outside-owned loan has reported incident; clean closure forbidden.');
            $dispatch=DB::table('hall_inventory_external_out_dispatches')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('hall_id',$hallId)->first();
            $handover=$dispatch ? DB::table('hall_inventory_external_out_handovers')
                ->where('company_id',$companyId)->where('id',$dispatch->handover_id)
                ->where('loan_id',$loanId)->where('hall_id',$hallId)->first() : null;
            if(!$dispatch||!$handover||
                (int)$handover->witnessed_by===(int)$loan->created_by||
                (int)$handover->witnessed_by===(int)$dispatch->approved_by||
                (int)$loan->created_by===(int)$dispatch->approved_by)
                throw new ConflictHttpException('Original outside dispatch lacked independent partner handover and approval.');
            $this->verify($handover);
            $draft=DB::table('hall_inventory_loan_draft_lines')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->orderBy('item_id')->lockForUpdate()->get(['item_id','catalog_hall_id','requested_quantity']);
            $count=DB::table('hall_inventory_external_out_handover_lines')
                ->where('company_id',$companyId)->where('handover_id',$handover->id)
                ->get(['item_id','counted_quantity'])->keyBy('item_id');
            $posted=DB::table('hall_inventory_external_out_dispatch_lines')
                ->where('company_id',$companyId)->where('dispatch_id',$dispatch->id)
                ->where('hall_id',$hallId)->get(['item_id','quantity'])->keyBy('item_id');
            if($draft->isEmpty()||$draft->count()!==$count->count()||
                $draft->count()!==$posted->count())
                throw new ConflictHttpException('Original signed outside-owned loan custody provenance incomplete.');
            $totals=[];
            foreach($draft as $line){
                $id=(string)$line->item_id;
                $qty=(int)$line->requested_quantity;
                if($line->catalog_hall_id!==$hallId||!$count->has($id)||
                    !$posted->has($id)||(int)$count[$id]->counted_quantity!==$qty||
                    (int)$posted[$id]->quantity!==$qty)
                    throw new ConflictHttpException('Original outside owned loan and witnessed dispatch item mismatch.');
                $totals[$id]=['original'=>$qty,'returned'=>0,'good'=>0];
            }
            $batches=DB::table('hall_inventory_external_out_return_receipts')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('hall_id',$hallId)->orderBy('id')->lockForUpdate()->get();
            if($batches->isEmpty())throw new ConflictHttpException('Outside owned loan has no actual partner-signed return.');
            $reviewIds=[];
            foreach($batches as $batch){
                if($batch->dispatch_id!==$dispatch->id||
                    (int)$batch->received_by===(int)$closer->getKey())
                    throw new AuthorizationException('Final closer may not self-verify physical outside return custody.');
                $this->verify($batch);
                $review=DB::table('hall_inventory_external_out_return_reviews')
                    ->where('company_id',$companyId)->where('receipt_id',$batch->id)
                    ->where('loan_id',$loanId)->where('hall_id',$hallId)->first();
                if(!$review||(int)$review->reviewed_by===(int)$batch->received_by)
                    throw new ConflictHttpException('Every signed outside return needs separate actual condition review.');
                $reviewIds[]=(string)$review->id;
                $received=DB::table('hall_inventory_external_out_return_lines')
                    ->where('company_id',$companyId)->where('receipt_id',$batch->id)
                    ->get(['item_id','quantity'])->keyBy('item_id');
                $assessed=DB::table('hall_inventory_external_out_return_review_lines')
                    ->where('company_id',$companyId)->where('receipt_id',$batch->id)
                    ->where('review_id',$review->id)
                    ->get(['item_id','good_quantity','damaged_quantity',
                        'unresolved_quantity','good_movement_id'])->keyBy('item_id');
                if($received->isEmpty()||$received->count()!==$assessed->count())
                    throw new ConflictHttpException('A signed outside return lacks exact inspected lines.');
                foreach($received as $itemId=>$line){
                    if(!array_key_exists((string)$itemId,$totals)||
                        !$assessed->has((string)$itemId))
                        throw new ConflictHttpException('Partner return references another original owned loan item.');
                    $actual=(int)$line->quantity;$r=$assessed[$itemId];
                    if((int)$r->good_quantity!==$actual||
                        (int)$r->damaged_quantity!==0||
                        (int)$r->unresolved_quantity!==0||!$r->good_movement_id)
                        throw new ConflictHttpException('Damaged or unresolved outside-owned custody cannot close CLEAN.');
                    $move=DB::table('hall_inventory_movement_events')
                        ->where('company_id',$companyId)->where('hall_id',$hallId)
                        ->where('item_id',$itemId)->where('id',$r->good_movement_id)
                        ->where('event_kind','return_owned_good')
                        ->where('quantity',$actual)
                        ->where('actor_id',$review->reviewed_by)->first();
                    if(!$move||$move->reason!=='Independently assessed outside owned return '.$batch->id)
                        throw new ConflictHttpException('Original independently approved good return movement is invalid.');
                    $totals[(string)$itemId]['returned']+=$actual;
                    $totals[(string)$itemId]['good']+=$actual;
                }
            }
            foreach($totals as $quantities)
                if($quantities['returned']!==$quantities['original']||
                   $quantities['good']!==$quantities['original'])
                    throw new ConflictHttpException('All outside-owned units are not yet independently returned GOOD.');
            $fingerprint=hash('sha256',json_encode([
                'company'=>$companyId,'loan'=>$loanId,'hall'=>$hallId,
                'dispatch'=>$dispatch->id,'handoverSha256'=>$handover->evidence_sha256,
                'lines'=>$totals,'returnReceipts'=>$batches->pluck('id')->all(),
                'independentReviews'=>$reviewIds
            ],JSON_THROW_ON_ERROR));
            $number=1+(int)DB::table('hall_inventory_external_out_clean_closures')
                ->where('company_id',$companyId)->max('receipt_number');
            $pdf=$this->renderPdf($companyId,$loanId,$number,$fingerprint,$totals);
            $pdfHash=hash('sha256',$pdf);
            $key='private/hall-external-out-clean-closures/'.$companyId.'/'.$closureId.'.pdf';
            $disk=Storage::disk('local');
            if(!$disk->put($key,$pdf))
                throw new ConflictHttpException('Immutable outside owned clean PDF storage failed.');
            try{
                DB::table('hall_inventory_external_out_clean_closures')->insert([
                    'id'=>$closureId,'company_id'=>$companyId,'loan_id'=>$loanId,
                    'hall_id'=>$hallId,'receipt_number'=>$number,
                    'reconciliation_sha256'=>$fingerprint,
                    'pdf_sha256'=>$pdfHash,'pdf_size'=>strlen($pdf),
                    'pdf_storage_path'=>$key,'closed_by'=>$closer->getKey()
                ]);
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'actor_id'=>$closer->getKey(),
                    'action'=>'hall_inventory.external_owned_clean_return_closed',
                    'entity_type'=>'hall_inventory_external_out_clean_closure',
                    'entity_id'=>$closureId,
                    'before_values'=>json_encode(['closed'=>false],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['closed'=>true,'receiptNumber'=>$number,
                        'reconciliationSha256'=>$fingerprint,
                        'financialPosting'=>false],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['loanId'=>$loanId,'hallId'=>$hallId,
                        'allOutsideOwnedReturnedGood'=>true],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e){$disk->delete($key);throw $e;}
            return ['id'=>$closureId,'loanId'=>$loanId,'receiptNumber'=>$number,
                'reconciliationSha256'=>$fingerprint,'financialPosting'=>false];
        },3);
    }
    private function summary(object $row):array {
        return ['id'=>(string)$row->id,'loanId'=>(string)$row->loan_id,
            'receiptNumber'=>(int)$row->receipt_number,
            'reconciliationSha256'=>(string)$row->reconciliation_sha256,
            'financialPosting'=>false];
    }
    public function summaryForLoan(User $actor,string $companyId,string $loanId):?array {
        $this->gate();
        if(!Str::isUuid($companyId)||!Str::isUuid($loanId))
            throw new InvalidArgumentException('Invalid outside owned clean receipt reference.');
        $row=DB::table('hall_inventory_external_out_clean_closures')
            ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
        if(!$row)return null;
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$row->hall_id)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Current source owner/admin needed to access numbered EOL PDF.');
        return $this->summary($row);
    }
    public function receiptPdf(User $actor,string $companyId,string $loanId):string {
        if(!$this->summaryForLoan($actor,$companyId,$loanId))
            throw new ConflictHttpException('No clean outside owned lending receipt recorded.');
        $row=DB::table('hall_inventory_external_out_clean_closures')
            ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
        return $this->pdfBytes($row);
    }
    private function pdfBytes(object $row):string {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->pdf_storage_path))
            throw new ConflictHttpException('Immutable EOL numbered PDF missing.');
        $bytes=$disk->get((string)$row->pdf_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->pdf_size||
            !hash_equals((string)$row->pdf_sha256,hash('sha256',$bytes))||
            !str_starts_with($bytes,'%PDF-1.4'))
            throw new ConflictHttpException('Immutable EOL receipt PDF integrity mismatch.');
        return $bytes;
    }
    private function renderPdf(string $companyId,string $loanId,int $number,string $hash,array $counts):string {
        $lines=[
            'VENUEDESK - CLEAN EXTERNAL OWNED LENDING RETURN',
            'Receipt EOL-'.(string)$number,
            'Company: '.$companyId,
            'Outside owned lending loan: '.$loanId,
            'All hall-owned units physically returned clean from outside partner',
            'No financial obligation or penalty is created by this receipt',
            'Reconciliation SHA256: '.$hash
        ];
        foreach($counts as $id=>$row)
            $lines[]='Item '.$id.' - independently inspected good return '.(string)$row['good'];
        $chunks=array_chunk($lines,48);
        $fontId=3+count($chunks)*2;
        $ids=[];
        foreach($chunks as $i=>$chunk)$ids[]=3+$i*2;
        $objects=[
            1=>'<< /Type /Catalog /Pages 2 0 R >>',
            2=>'<< /Type /Pages /Kids ['.implode(' ',array_map(
                static fn(int $i):string=>$i.' 0 R',$ids
            )).'] /Count '.count($chunks).' >>'
        ];
        foreach($chunks as $i=>$chunk) {
            $page=3+$i*2;$stream=$page+1;
            $commands="BT /F1 10 Tf 34 770 Td 14 TL\n";
            foreach($chunk as $label) {
                $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$label);
                $commands.='('.$escaped.") Tj T*\n";
            }
            $commands.="ET\n";
            $objects[$page]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$stream.' 0 R >>';
            $objects[$stream]='<< /Length '.strlen($commands)." >>\nstream\n".$commands."endstream";
        }
        $objects[$fontId]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects,SORT_NUMERIC);
        $pdf="%PDF-1.4\n";$offsets=[0];
        foreach($objects as $i=>$part) {
            $offsets[$i]=strlen($pdf);
            $pdf.=$i." 0 obj\n".$part."\nendobj\n";
        }
        $xref=strlen($pdf);
        $pdf.="xref\n0 ".($fontId+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=$fontId;$i++)
            $pdf.=sprintf('%010d 00000 n ', $offsets[$i])."\n";
        return $pdf."trailer\n<< /Size ".($fontId+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
