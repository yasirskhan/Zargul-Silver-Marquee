<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use App\Policies\FinanceAccessPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Final EXTERNAL_IN non-clean closure from a green readiness assessment.
 * Writes off only the independently settled missing borrowed transit and
 * issues one company-numbered immutable private PDF. No owned stock, cash
 * mutation or new financial posting is created here.
 */
final class HallInventoryExternalInNoncleanClosureService
{
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $transition) {}

    private function gate():void {
        foreach(['loan_drafts_enabled','external_borrowed_intake_enabled',
            'external_borrowed_return_enabled','external_borrowed_discrepancy_enabled',
            'external_in_loss_obligation_enabled','external_in_loss_settlement_enabled',
            'external_in_nonclean_closure_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new ConflictHttpException('External-in non-clean final closure is not released.');
        if(config('finance_controls.posting_enabled')!==true)
            throw new ConflictHttpException('Financial journal release is OFF.');
    }
    private function state(object $position):array {
        return ['ownedUsable'=>(int)$position->owned_usable,
            'ownedOnLoan'=>(int)$position->owned_on_loan,
            'ownedDamaged'=>(int)$position->owned_damaged,
            'borrowedUsable'=>(int)$position->borrowed_usable,
            'borrowedTransit'=>(int)$position->borrowed_transit,
            'borrowedDamaged'=>(int)$position->borrowed_damaged];
    }
    private function movement(User $actor,string $companyId,string $hallId,string $itemId,
        object $position,int $quantity,string $closureId,string $reason):string {
        $before=$this->state($position);
        $after=$this->transition->apply($before,'writeoff_borrowed_transit',$quantity);
        $revision=(int)$position->revision;
        $updated=DB::table('hall_inventory_positions')->where('company_id',$companyId)
            ->where('hall_id',$hallId)->where('item_id',$itemId)->where('id',$position->id)
            ->where('revision',$revision)->update([
                'borrowed_transit'=>$after['borrowedTransit'],
                'revision'=>$revision+1,'updated_at'=>now()
            ]);
        if($updated!==1)
            throw new ConflictHttpException('Concurrent borrowed transit position changed.');
        $id=(string)Str::uuid();
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>$id,'company_id'=>$companyId,'hall_id'=>$hallId,'item_id'=>$itemId,
            'position_id'=>$position->id,'event_kind'=>'writeoff_borrowed_transit',
            'quantity'=>$quantity,'revision_before'=>$revision,'revision_after'=>$revision+1,
            'request_fingerprint'=>hash('sha256',$closureId.$itemId.$quantity),
            'reason'=>$reason,'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
            'actor_id'=>$actor->getKey()
        ]);
        return $id;
    }
    private function pdf(string $serial,string $loanId,string $hallId,string $currency,
        int $amountMinor,int $missing,int $damaged):string {
        $lines=['VenueDesk - External Borrowed Non-Clean Final Closure','Closure: '.$serial,
            'Borrowing hall: '.$hallId,'Original loan: '.$loanId,
            'Missing borrowed units written off: '.$missing,
            'Partner-reported damaged units: '.$damaged,
            'Settled obligations: '.$amountMinor.' '.$currency.' (minor units)',
            'Missing borrowed transit after closure: 0',
            'No owned stock retirement and no cash mutation by this certificate.',
            'Issued UTC: '.now('UTC')->toDateTimeString()];
        $stream='BT /F1 11 Tf 50 760 Td 16 TL ';
        foreach($lines as $line)
            $stream.='('.str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line).') Tj T* ';
        $stream.='ET';
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',
            2=>'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3=>'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4=>'<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream",
            5=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pdf="%PDF-1.4\n";$offsets=[0];
        foreach($objects as $i=>$object) {
            $offsets[$i]=strlen($pdf);
            $pdf.=$i." 0 obj\n".$object."\nendobj\n";
        }
        $xref=strlen($pdf);
        $pdf.="xref\n0 6\n0000000000 65535 f \n";
        for($i=1;$i<=5;$i++)$pdf.=sprintf('%010d 00000 n ',$offsets[$i])."\n";
        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
    private function summary(object $row):array {
        return ['id'=>(string)$row->id,'loanId'=>(string)$row->loan_id,
            'serialNumber'=>(string)$row->serial_number,
            'currency'=>(string)$row->currency,'amountMinor'=>(int)$row->amount_minor,
            'missingQuantity'=>(int)$row->missing_quantity,
            'damagedQuantity'=>(int)$row->damaged_quantity,
            'pdfSha256'=>(string)$row->pdf_sha256,'pdfSize'=>(int)$row->pdf_size,
            'closedBy'=>(int)$row->closed_by,'closedAt'=>(string)$row->created_at,
            'readiness'=>json_decode((string)$row->readiness,true,512,JSON_THROW_ON_ERROR),
            'moneyMoved'=>false,'ownedStockRetired'=>false];
    }
    public function close(User $actor,string $companyId,string $hallId,string $loanId,
        string $closureId):array {
        $this->gate();
        foreach([$companyId,$hallId,$loanId,$closureId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact closure UUIDs are required.');
        return DB::transaction(function()use($actor,$companyId,$hallId,$loanId,$closureId):array {
            abort_unless(DB::table('companies')->where('id',$companyId)
                ->where('is_active',true)->lockForUpdate()->exists(),403);
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||
                (string)$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Closure requires the original borrowing-hall loan.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall)||
                !(new FinanceAccessPolicy())->post($actor,$companyId))
                throw new AuthorizationException('Current borrowing-hall owner/admin finance access required.');
            $old=DB::table('hall_inventory_external_in_nonclean_closures')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old) {
                if((string)$old->id!==$closureId||(int)$old->closed_by!==(int)$actor->getKey())
                    throw new ConflictHttpException('Loan already has a different immutable non-clean closure.');
                $this->verifyPdf($old);
                return $this->summary($old);
            }
            $readiness=app(HallInventoryExternalInNoncleanReadinessService::class)
                ->assess($actor,$companyId,$hallId,$loanId);
            if(!$readiness['ready'])
                throw new ConflictHttpException('Non-clean closure is blocked: '.implode(' ',$readiness['blockers']));
            $substantiated=DB::table('hall_inventory_external_in_discrepancies as d')
                ->join('hall_inventory_external_in_discrepancy_decisions as x',function($j):void {
                    $j->on('x.company_id','=','d.company_id')->on('x.discrepancy_id','=','d.id');
                })->where('d.company_id',$companyId)->where('d.loan_id',$loanId)
                ->where('x.decision','substantiated')->pluck('d.id')->all();
            $missingByItem=[];
            if($substantiated!==[]) {
                $lines=DB::table('hall_inventory_external_in_discrepancy_lines')
                    ->where('company_id',$companyId)->whereIn('discrepancy_id',$substantiated)
                    ->get(['item_id','shipped_quantity','received_quantity']);
                foreach($lines as $line) {
                    $item=(string)$line->item_id;
                    $missingByItem[$item]=($missingByItem[$item]??0)+
                        max(0,(int)$line->shipped_quantity-(int)$line->received_quantity);
                }
            }
            foreach($missingByItem as $itemId=>$missing) {
                if($missing<1)continue;
                $position=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                    ->where('hall_id',$hallId)->where('item_id',$itemId)->lockForUpdate()->first();
                if(!$position||(int)$position->borrowed_transit!==$missing)
                    throw new ConflictHttpException('Borrowed transit does not exactly match the settled missing quantity.');
                $this->movement($actor,$companyId,$hallId,$itemId,$position,$missing,$closureId,
                    'Settled missing borrowed transit for non-clean loan '.$loanId);
            }
            $remaining=(int)DB::table('hall_inventory_positions')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->whereIn('item_id',array_keys($missingByItem))
                ->sum('borrowed_transit');
            if($remaining!==0)
                throw new ConflictHttpException('Borrowed transit remains after the final write-off.');
            $approved=DB::table('hall_inventory_external_in_loss_obligations as o')
                ->join('hall_inventory_external_in_loss_obligation_decisions as d',function($j):void {
                    $j->on('d.company_id','=','o.company_id')->on('d.obligation_id','=','o.id');
                })->where('o.company_id',$companyId)->where('o.loan_id',$loanId)
                ->where('d.decision','approved')->get(['o.currency','o.amount_minor']);
            $currencies=array_values(array_unique($approved->pluck('currency')->all()));
            if(count($currencies)!==1)
                throw new ConflictHttpException('Settled partner obligations must share one currency.');
            $currency=(string)$currencies[0];
            $amount=(int)$approved->sum('amount_minor');
            $serialSeq=((int)DB::table('hall_inventory_external_in_nonclean_closures')
                ->where('company_id',$companyId)->max('serial_seq'))+1;
            $serial='ENCL-'.$serialSeq;
            $pdf=$this->pdf($serial,$loanId,$hallId,$currency,$amount,
                (int)$readiness['partnerReportedMissing'],(int)$readiness['partnerReportedDamaged']);
            $sha=hash('sha256',$pdf);$size=strlen($pdf);
            $path='private/hall-external-in-nonclean/'.$companyId.'/'.$loanId.'/'.$closureId.'.pdf';
            $disk=Storage::disk('local');
            if($disk->exists($path))throw new ConflictHttpException('Closure PDF storage key already occupied.');
            if(!$disk->put($path,$pdf))throw new ConflictHttpException('Closure PDF could not be stored.');
            try {
                DB::table('hall_inventory_external_in_nonclean_closures')->insert([
                    'id'=>$closureId,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'loan_id'=>$loanId,'serial_seq'=>$serialSeq,'serial_number'=>$serial,
                    'currency'=>$currency,'amount_minor'=>$amount,
                    'missing_quantity'=>(int)$readiness['partnerReportedMissing'],
                    'damaged_quantity'=>(int)$readiness['partnerReportedDamaged'],
                    'pdf_sha256'=>$sha,'pdf_size'=>$size,'pdf_path'=>$path,
                    'readiness'=>json_encode($readiness,JSON_THROW_ON_ERROR),
                    'closed_by'=>$actor->getKey()
                ]);
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                    'action'=>'hall_inventory.external_in_nonclean_closed',
                    'entity_type'=>'hall_inventory_external_in_nonclean_closure',
                    'entity_id'=>$closureId,
                    'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['serial'=>$serial,'currency'=>$currency,
                        'amountMinor'=>$amount,'missing'=>$readiness['partnerReportedMissing'],
                        'damaged'=>$readiness['partnerReportedDamaged'],'pdfSha256'=>$sha,
                        'ownedStockRetired'=>false,'moneyMoved'=>false],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['loanId'=>$loanId,'hallId'=>$hallId],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e){$disk->delete($path);throw $e;}
            return $this->summary(DB::table('hall_inventory_external_in_nonclean_closures')
                ->where('id',$closureId)->first());
        },3);
    }
    public function summaryForLoan(User $actor,string $companyId,string $hallId,string $loanId):?array {
        $this->gate();
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Closure history is hall-restricted.');
        $row=DB::table('hall_inventory_external_in_nonclean_closures')
            ->where('company_id',$companyId)->where('hall_id',$hallId)->where('loan_id',$loanId)->first();
        return $row?$this->summary($row):null;
    }
    public function download(User $actor,string $companyId,string $hallId,string $closureId):array {
        $this->gate();
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Closure PDF is hall-restricted.');
        $row=DB::table('hall_inventory_external_in_nonclean_closures')
            ->where('company_id',$companyId)->where('hall_id',$hallId)->where('id',$closureId)->first();
        if(!$row)throw new ConflictHttpException('Closure PDF was not found.');
        $this->verifyPdf($row);
        return ['bytes'=>Storage::disk('local')->get((string)$row->pdf_path),
            'mime'=>'application/pdf','name'=>'external-in-nonclean-'.$row->serial_number.'.pdf'];
    }
    private function verifyPdf(object $row):void {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->pdf_path))
            throw new ConflictHttpException('Closure PDF is missing.');
        $bytes=$disk->get((string)$row->pdf_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->pdf_size||
            !hash_equals((string)$row->pdf_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Closure PDF integrity verification failed.');
    }
}
