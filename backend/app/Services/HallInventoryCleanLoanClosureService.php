<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Exact clean physical custody closeout only. Damaged, missing, disputed and
 * financially unresolved cases require a separate approved obligation flow.
 * This record never accepts a caller-supplied quantity or money amount.
 */
final class HallInventoryCleanLoanClosureService
{
    public function __construct(private readonly TenantAccess $access) {}

    public function close(User $actor,string $companyId,string $loanId,string $closureId):array
    {
        $this->gated();
        if(!Str::isUuid($companyId)||!Str::isUuid($loanId)||!Str::isUuid($closureId))
            throw new InvalidArgumentException('Valid company, loan and closure keys required.');
        return DB::transaction(function()use($actor,$companyId,$loanId,$closureId):array {
            // Company lock serializes all receipt numbering, even for separate loans.
            $company=DB::table('companies')->where('id',$companyId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$company)throw new AuthorizationException('Inactive inventory company.');
            $loan=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$loanId)
                ->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='internal')
                throw new ConflictHttpException('Only internal loans may use clean closure.');
            $hall=Hall::query()->where('company_id',$companyId)
                ->where('id',$loan->lending_hall_id)->where('is_active',true)->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current lending-hall owner/admin required.');
            $old=DB::table('hall_inventory_internal_loan_closures')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old) {
                if((string)$old->id!==$closureId||(int)$old->closed_by!==(int)$actor->getKey())
                    throw new ConflictHttpException('This loan already has an immutable completion receipt.');
                $this->verifiedPdfBytes($old);
                return $this->summary($old);
            }
            $dispatch=DB::table('hall_inventory_loan_dispatches')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            $receipt=DB::table('hall_inventory_internal_loan_receipts')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if(!$dispatch||!$receipt||$receipt->dispatch_id!==$dispatch->id)
                throw new ConflictHttpException('Independent dispatch and destination receipt required.');
            if((int)$dispatch->dispatched_by===(int)$actor->getKey()||
                (int)$receipt->received_by===(int)$actor->getKey())
                throw new AuthorizationException('Final closer must be separate from custody handover actors.');
            if(DB::table('hall_inventory_loan_incident_reports')->where('company_id',$companyId)
                ->where('loan_id',$loanId)->exists())
                throw new ConflictHttpException('Incident reported: independent obligation resolution required.');
            $sent=DB::table('hall_inventory_loan_dispatch_lines')
                ->where('company_id',$companyId)->where('dispatch_id',$dispatch->id)
                ->orderBy('item_id')->get(['item_id','quantity']);
            $received=DB::table('hall_inventory_internal_loan_receipt_lines')
                ->where('company_id',$companyId)->where('receipt_id',$receipt->id)
                ->get(['source_item_id','quantity'])->keyBy('source_item_id');
            if($sent->isEmpty()||$sent->count()!==$received->count())
                throw new ConflictHttpException('Dispatch and independent receipt lines must match.');
            $final=[];
            foreach($sent as $line) {
                $item=(string)$line->item_id;
                if(!$received->has($item)||
                    (int)$received[$item]->quantity!==(int)$line->quantity)
                    throw new ConflictHttpException('Independent received quantity differs from dispatched loan.');
                $return=DB::table('hall_inventory_internal_loan_return_acceptance_lines as al')
                    ->join('hall_inventory_internal_loan_return_acceptances as a',
                        function($j):void {$j->on('a.company_id','=','al.company_id')
                            ->on('a.id','=','al.acceptance_id');})
                    ->join('hall_inventory_internal_loan_return_batches as b',
                        function($j):void {$j->on('b.company_id','=','a.company_id')
                            ->on('b.id','=','a.return_batch_id');})
                    ->where('al.company_id',$companyId)->where('b.loan_id',$loanId)
                    ->where('al.source_item_id',$item)
                    ->selectRaw('COALESCE(SUM(al.good_quantity),0) as good')
                    ->selectRaw('COALESCE(SUM(al.damaged_quantity),0) as damaged')
                    ->selectRaw('COALESCE(SUM(al.unresolved_quantity),0) as unresolved')
                    ->first();
                if((int)($return->good??0)!==(int)$line->quantity||
                    (int)($return->damaged??0)!==0||(int)($return->unresolved??0)!==0)
                    throw new ConflictHttpException('Not every loan unit was independently received back in good condition.');
                $final[]=['itemId'=>$item,'quantity'=>(int)$line->quantity];
            }
            // Every shipped return must have a source acceptance. No in-transit
            // lines, unacknowledged batches or provisional evidence allowed.
            if(DB::table('hall_inventory_internal_loan_return_batches as b')
                ->leftJoin('hall_inventory_internal_loan_return_acceptances as a',
                    function($j):void {$j->on('a.company_id','=','b.company_id')
                        ->on('a.return_batch_id','=','b.id');})
                ->where('b.company_id',$companyId)->where('b.loan_id',$loanId)
                ->whereNull('a.id')->exists())
                throw new ConflictHttpException('An in-transit return still lacks source acknowledgment.');
            $fingerprint=hash('sha256',json_encode([$companyId,$loanId,$final],JSON_THROW_ON_ERROR));
            $number=(int)DB::table('hall_inventory_internal_loan_closures')
                ->where('company_id',$companyId)->max('receipt_number')+1;
            $bytes=$this->renderReceiptPdf($companyId,$loanId,$number,$fingerprint,$final);
            $pdfSha=hash('sha256',$bytes);
            $pdfPath='private/hall-inventory-loan-closures/'.$companyId.'/'.$closureId.'.pdf';
            $disk=Storage::disk('local');
            if(!$disk->put($pdfPath,$bytes))
                throw new ConflictHttpException('Immutable numbered receipt storage failed.');
            try {
            DB::table('hall_inventory_internal_loan_closures')->insert([
                'id'=>$closureId,'company_id'=>$companyId,'loan_id'=>$loanId,
                'source_hall_id'=>$hall->id,'receipt_number'=>$number,
                'reconciliation_sha256'=>$fingerprint,
                'pdf_sha256'=>$pdfSha,'pdf_size'=>strlen($bytes),
                'pdf_storage_path'=>$pdfPath,'closed_by'=>$actor->getKey()
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$actor->getKey(),
                'action'=>'hall_inventory.internal_clean_loan_closed',
                'entity_type'=>'hall_inventory_internal_loan_closure',
                'entity_id'=>$closureId,
                'before_values'=>json_encode(['closed'=>false],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['closed'=>true,'number'=>$number,
                    'reconciliation'=>$fingerprint],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['loanId'=>$loanId,'hallId'=>$hall->id,
                    'noFinancialPosting'=>true],JSON_THROW_ON_ERROR)
            ]);
            }catch(\Throwable $e) {
                $disk->delete($pdfPath);
                throw $e;
            }
            return ['id'=>$closureId,'loanId'=>$loanId,'receiptNumber'=>$number,
                'reconciliationSha256'=>$fingerprint,'financialPosting'=>false];
        },3);
    }

    public function summaryForLoan(User $actor,string $companyId,string $loanId):?array
    {
        $this->gated();
        if(!Str::isUuid($companyId)||!Str::isUuid($loanId))
            throw new InvalidArgumentException('Invalid loan receipt lookup.');
        $row=DB::table('hall_inventory_internal_loan_closures as c')
            ->join('hall_inventory_loan_drafts as d',function($j):void {
                $j->on('d.company_id','=','c.company_id')->on('d.id','=','c.loan_id');
            })->where('c.company_id',$companyId)->where('c.loan_id',$loanId)
            ->first(['c.*','d.lending_hall_id']);
        if(!$row)return null;
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$row->source_hall_id)->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Private source closure review denied.');
        return $this->summary($row);
    }

    public function receiptPdf(User $actor,string $companyId,string $loanId):string
    {
        $summary=$this->summaryForLoan($actor,$companyId,$loanId);
        if(!$summary)throw new ConflictHttpException('Numbered loan receipt does not exist.');
        $row=DB::table('hall_inventory_internal_loan_closures')
            ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
        if(!$row)throw new ConflictHttpException('Numbered loan receipt disappeared.');
        return $this->verifiedPdfBytes($row);
    }

    private function verifiedPdfBytes(object $row):string
    {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->pdf_storage_path))
            throw new ConflictHttpException('Signed-off receipt evidence is missing.');
        $bytes=$disk->get((string)$row->pdf_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->pdf_size||
            !hash_equals((string)$row->pdf_sha256,hash('sha256',$bytes))||
            !str_starts_with($bytes,'%PDF-1.4'))
            throw new ConflictHttpException('Numbered PDF receipt integrity failed.');
        return $bytes;
    }

    private function renderReceiptPdf(string $companyId,string $loanId,int $number,
        string $fingerprint,array $lines):string
    {
        $texts=['VENUEDESK - CLEAN INTERNAL LOAN RETURN RECEIPT',
            'Receipt number: '.(string)$number,'Company: '.$companyId,
            'Loan: '.$loanId,'Physical return: fully reconciled in good condition',
            'No financial charge or settlement was posted',
            'Reconciliation SHA256: '.$fingerprint];
        foreach($lines as $line)
            $texts[]='Item '.$line['itemId'].' - returned '.(string)$line['quantity'];
        $pages=array_chunk($texts,48);
        $fontId=3+count($pages)*2;
        $pageIds=[];
        foreach($pages as $i=>$chunk)$pageIds[]=3+$i*2;
        $objects=[
            1=>'<< /Type /Catalog /Pages 2 0 R >>',
            2=>'<< /Type /Pages /Kids ['.implode(' ',array_map(
                static fn(int $id):string=>$id.' 0 R',$pageIds
            )).'] /Count '.count($pages).' >>'
        ];
        foreach($pages as $i=>$chunk) {
            $pageId=3+$i*2;$contentId=$pageId+1;
            $ops="BT /F1 10 Tf 34 770 Td 14 TL\n";
            foreach($chunk as $text) {
                $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$text);
                $ops.='('.$escaped.") Tj T*\n";
            }
            $ops.="ET\n";
            $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId]='<< /Length '.strlen($ops)." >>\nstream\n".$ops."endstream";
        }
        $objects[$fontId]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects,SORT_NUMERIC);
        $pdf="%PDF-1.4\n";$offsets=[0];
        foreach($objects as $id=>$object) {
            $offsets[$id]=strlen($pdf);
            $pdf.=$id." 0 obj\n".$object."\nendobj\n";
        }
        $start=strlen($pdf);
        $pdf.="xref\n0 ".($fontId+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=$fontId;$i++)
            $pdf.=sprintf('%010d 00000 n ', $offsets[$i])."\n";
        return $pdf."trailer\n<< /Size ".($fontId+1)." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";
    }

    private function summary(object $row):array
    {
        return ['id'=>(string)$row->id,'loanId'=>(string)$row->loan_id,
            'receiptNumber'=>(int)$row->receipt_number,
            'reconciliationSha256'=>(string)$row->reconciliation_sha256,
            'financialPosting'=>false];
    }

    private function gated():void
    {
        if(!config('inventory_controls.loan_clean_closure_enabled')||
            !config('inventory_controls.loan_returns_enabled')||
            !config('inventory_controls.loan_return_acceptance_enabled'))
            throw new ConflictHttpException('Clean internal-loan final closure is disabled.');
    }
}
