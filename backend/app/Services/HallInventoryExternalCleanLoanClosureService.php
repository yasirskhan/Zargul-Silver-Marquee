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

/**
 * Exact external_in custody-only closeout. A partner-signed, independently
 * verified return of every original unit is required. No loss/penalty/payment
 * can be assumed or settled by this receipt.
 */
final class HallInventoryExternalCleanLoanClosureService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gated():void {
        if(!config('inventory_controls.loan_drafts_enabled') ||
            !config('inventory_controls.external_borrowed_intake_enabled') ||
            !config('inventory_controls.external_borrowed_return_enabled') ||
            !config('inventory_controls.external_borrowed_clean_closure_enabled'))
            throw new ConflictHttpException('Partner borrowed clean closure is not released.');
    }
    private function verifyEvidence(object $row):void {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->evidence_storage_path))
            throw new ConflictHttpException('Required original signed custody evidence is missing.');
        $bytes=$disk->get((string)$row->evidence_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
            !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Original physical evidence hash or size does not match.');
    }
    public function close(User $actor,string $companyId,string $loanId,string $closureId):array {
        $this->gated();
        foreach([$companyId,$loanId,$closureId] as $id)
            if(!Str::isUuid($id))
                throw new InvalidArgumentException('Exact valid outside loan and closure identifiers required.');
        return DB::transaction(function()use($actor,$companyId,$loanId,$closureId):array {
            // Lock company before loan for serial company-local receipt numbering.
            $company=DB::table('companies')->where('id',$companyId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$company)throw new AuthorizationException('Inactive partner custody company.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in')
                throw new ConflictHttpException('Only signed outside-to-hall loans can use external clean closeout.');
            $hallId=(string)$loan->borrowing_hall_id;
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall)||
                (int)$loan->created_by===(int)$actor->getKey())
                throw new AuthorizationException('Independent current borrowing-hall owner/admin required.');
            $existing=DB::table('hall_inventory_external_in_clean_closures')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($existing) {
                if((string)$existing->id!==$closureId||(int)$existing->closed_by!==(int)$actor->getKey())
                    throw new ConflictHttpException('An external loan already has its own immutable clean receipt.');
                $this->receiptBytes($existing);
                return $this->summary($existing);
            }
            if(DB::table('hall_inventory_loan_incident_reports')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->exists())
                throw new ConflictHttpException('Loan incident has an unresolved independent investigation; clean closeout forbidden.');
            $receipt=DB::table('hall_inventory_external_in_receipts')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('hall_id',$hallId)->first();
            $accept=DB::table('hall_inventory_external_in_acceptances')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('hall_id',$hallId)->first();
            if(!$receipt||!$accept||(int)$receipt->received_by===(int)$actor->getKey()||
                (int)$accept->approved_by===(int)$loan->created_by||
                (int)$accept->approved_by===(int)$receipt->received_by)
                throw new ConflictHttpException('Separate physical outside intake and independent approval required.');
            $this->verifyEvidence($receipt);
            $draftLines=DB::table('hall_inventory_loan_draft_lines')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->orderBy('item_id')->lockForUpdate()->get(['item_id','requested_quantity','catalog_hall_id']);
            $accepted=DB::table('hall_inventory_external_in_acceptance_lines')
                ->where('company_id',$companyId)->where('acceptance_id',$accept->id)
                ->orderBy('item_id')->get(['item_id','quantity'])->keyBy('item_id');
            $physical=DB::table('hall_inventory_external_in_receipt_lines')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('receipt_id',$receipt->id)
                ->get(['item_id','counted_quantity'])->keyBy('item_id');
            if($draftLines->isEmpty()||$draftLines->count()!==$accepted->count()||
                $draftLines->count()!==$physical->count())
                throw new ConflictHttpException('Original external accepted/draft/count evidence is incomplete.');
            $batchList=DB::table('hall_inventory_external_in_return_batches')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->orderBy('id')->lockForUpdate()->get();
            if($batchList->isEmpty())throw new ConflictHttpException('No signed physical partner returns recorded.');
            $totals=[];
            foreach($draftLines as $line) {
                $id=(string)$line->item_id;$qty=(int)$line->requested_quantity;
                if($line->catalog_hall_id!==$hallId||
                    !$accepted->has($id)||$qty!==(int)$accepted[$id]->quantity||
                    !$physical->has($id)||$qty!==(int)$physical[$id]->counted_quantity)
                    throw new ConflictHttpException('Original partner loan lines and independent intake are not exactly matched.');
                $totals[$id]=['original'=>$qty,'sent'=>0,'confirmed'=>0];
            }
            foreach($batchList as $batch) {
                if($batch->hall_id!==$hallId||
                    (int)$batch->dispatched_by===(int)$actor->getKey())
                    throw new AuthorizationException('Independent final closer must not ship partner return custody.');
                $this->verifyEvidence($batch);
                $confirmation=DB::table('hall_inventory_external_in_return_confirmations')
                    ->where('company_id',$companyId)->where('batch_id',$batch->id)
                    ->where('hall_id',$hallId)->first();
                if(!$confirmation || (int)$confirmation->confirmed_by===(int)$batch->dispatched_by)
                    throw new ConflictHttpException('Partner handover remains without independent final physical acknowledgment.');
                $this->verifyEvidence($confirmation);
                $sent=DB::table('hall_inventory_external_in_return_lines')
                    ->where('company_id',$companyId)->where('batch_id',$batch->id)
                    ->orderBy('item_id')->get(['item_id','quantity'])->keyBy('item_id');
                $got=DB::table('hall_inventory_external_in_return_confirmation_lines')
                    ->where('company_id',$companyId)->where('confirmation_id',$confirmation->id)
                    ->get(['item_id','quantity'])->keyBy('item_id');
                if($sent->isEmpty()||$got->count()!==$sent->count())
                    throw new ConflictHttpException('Return shipment lacks complete independently counted partner receipt.');
                foreach($sent as $item=>$row) {
                    if(!array_key_exists((string)$item,$totals)||!$got->has((string)$item)||
                        (int)$row->quantity!==(int)$got[$item]->quantity)
                        throw new ConflictHttpException('Shipped and actual partner-confirmed quantities do not match.');
                    $totals[(string)$item]['sent']+=(int)$row->quantity;
                    $totals[(string)$item]['confirmed']+=(int)$got[$item]->quantity;
                }
            }
            foreach($totals as $itemId=>$counts) {
                if($counts['sent']!==$counts['original']||
                    $counts['confirmed']!==$counts['original'])
                    throw new ConflictHttpException('External borrowed units are not all independently returned to partner.');
            }
            $fingerprint=hash('sha256',json_encode([
                'company'=>$companyId,'loan'=>$loanId,'hall'=>$hallId,
                'receivingReceipt'=>$receipt->id,'accepted'=>$accept->id,
                'lines'=>$totals,'returnedBatchIds'=>$batchList->pluck('id')->all(),
            ],JSON_THROW_ON_ERROR));
            $number=1+(int)DB::table('hall_inventory_external_in_clean_closures')
                ->where('company_id',$companyId)->max('receipt_number');
            $pdf=$this->renderPdf($companyId,$loanId,$number,$fingerprint,$totals);
            $pdfHash=hash('sha256',$pdf);
            $key='private/hall-external-clean-return-closures/'.$companyId.'/'.$closureId.'.pdf';
            $disk=Storage::disk('local');
            if($disk->exists($key))
                throw new ConflictHttpException('Immutable external borrowed receipt identifier already occupies private storage.');
            if(!$disk->put($key,$pdf))
                throw new ConflictHttpException('Private immutable partner return PDF could not be written.');
            try {
                DB::table('hall_inventory_external_in_clean_closures')->insert([
                    'id'=>$closureId,'company_id'=>$companyId,'loan_id'=>$loanId,
                    'hall_id'=>$hallId,'receipt_number'=>$number,
                    'reconciliation_sha256'=>$fingerprint,'pdf_sha256'=>$pdfHash,
                    'pdf_size'=>strlen($pdf),'pdf_storage_path'=>$key,
                    'closed_by'=>$actor->getKey()
                ]);
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                    'action'=>'hall_inventory.external_borrowed_clean_return_closed',
                    'entity_type'=>'hall_inventory_external_in_clean_closure','entity_id'=>$closureId,
                    'before_values'=>json_encode(['closed'=>false],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['closed'=>true,'receiptNumber'=>$number,
                        'reconciliationSha256'=>$fingerprint,'financialPosting'=>false],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['loanId'=>$loanId,'hallId'=>$hallId,
                        'physicalPartnerReturnVerified'=>true],JSON_THROW_ON_ERROR)
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
        $this->gated();
        if(!Str::isUuid($companyId)||!Str::isUuid($loanId))
            throw new InvalidArgumentException('Invalid external clean closure lookup.');
        $row=DB::table('hall_inventory_external_in_clean_closures')
            ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
        if(!$row)return null;
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$row->hall_id)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Only current owner/admin may access partner clean closure receipt.');
        return $this->summary($row);
    }
    public function receiptPdf(User $actor,string $companyId,string $loanId):string {
        if(!$this->summaryForLoan($actor,$companyId,$loanId))
            throw new ConflictHttpException('No external physical clean return receipt exists.');
        $row=DB::table('hall_inventory_external_in_clean_closures')
            ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
        return $this->receiptBytes($row);
    }
    private function receiptBytes(object $row):string {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->pdf_storage_path))
            throw new ConflictHttpException('Private immutable receipt PDF missing.');
        $bytes=$disk->get((string)$row->pdf_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->pdf_size||
            !hash_equals((string)$row->pdf_sha256,hash('sha256',$bytes))||
            !str_starts_with($bytes,'%PDF-1.4'))
            throw new ConflictHttpException('Immutable numbered physical partner return PDF integrity failed.');
        return $bytes;
    }
    private function renderPdf(string $companyId,string $loanId,int $number,string $hash,array $counts):string {
        $lines=[
            'VENUEDESK - CLEAN EXTERNAL BORROWED RETURN',
            'Receipt EX-'.(string)$number,
            'Company: '.$companyId,
            'Outside borrowed loan: '.$loanId,
            'All originally borrowed units physically returned to partner',
            'No financial obligation or penalty is created by this receipt',
            'Reconciliation SHA256: '.$hash
        ];
        foreach($counts as $id=>$row)
            $lines[]='Item '.$id.' - confirmed partner receipt '.(string)$row['confirmed'];
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
