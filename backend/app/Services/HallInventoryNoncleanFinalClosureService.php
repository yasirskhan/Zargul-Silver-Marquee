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
 * Default-OFF final non-clean outside-owned closure.
 * A historical loss is not cash. All physical and recognized money balances
 * must be zero before immutable numbered evidence can be issued.
 */
final class HallInventoryNoncleanFinalClosureService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate():void {
        foreach(['loan_nonclean_final_closure_enabled','loan_nonclean_reconciliation_enabled',
            'loan_nonclean_writeoffs_enabled','loan_financial_waivers_enabled',
            'loan_recoveries_enabled','loan_authorized_obligations_enabled',
            'external_out_dispatch_enabled','external_out_return_enabled'] as $flag)
            if(config('inventory_controls.'.$flag)!==true)
                throw new ConflictHttpException('Non-clean final closure is not released.');
    }

    private function scoped(User $actor,string $companyId,string $hallId):void {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Current original lending hall owner/admin required.');
    }

    private function bytes(object $row):string {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->pdf_storage_path))
            throw new ConflictHttpException('Original numbered non-clean document unavailable.');
        $bytes=$disk->get((string)$row->pdf_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->pdf_size||
            !hash_equals((string)$row->pdf_sha256,hash('sha256',$bytes))||
            !str_starts_with($bytes,'%PDF-1.4'))
            throw new ConflictHttpException('Immutable numbered document hash mismatch.');
        return $bytes;
    }

    public function close(User $actor,string $companyId,string $hallId,string $loanId,string $id):array {
        $this->gate();
        foreach([$companyId,$hallId,$loanId,$id] as $uuid)
            if(!Str::isUuid($uuid))throw new InvalidArgumentException('Exact closure UUID required.');
        $this->scoped($actor,$companyId,$hallId);
        return DB::transaction(function()use($actor,$companyId,$hallId,$loanId,$id):array {
            $company=DB::table('companies')->where('id',$companyId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$company)throw new AuthorizationException('Inactive company cannot close inventory.');
            $loan=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$loanId)
                ->where('lending_hall_id',$hallId)->where('direction','external_out')
                ->lockForUpdate()->first();
            if(!$loan)throw new AuthorizationException('Original outside-owned loan unavailable.');
            if((int)$loan->created_by===(int)$actor->getKey())
                throw new AuthorizationException('Loan creator cannot independently issue final document.');
            $old=DB::table('hall_inventory_nonclean_final_closures')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old) {
                if((string)$old->id!==$id||(int)$old->closed_by!==(int)$actor->getKey())
                    throw new ConflictHttpException('An immutable final closure already exists.');
                $this->bytes($old);
                return ['id'=>$id,'receiptNumber'=>(int)$old->receipt_number];
            }
            if(DB::table('hall_inventory_external_out_clean_closures')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->exists())
                throw new ConflictHttpException('Loan already closed in the clean EOL series.');
            $snapshot=app(HallInventoryNoncleanReconciliationService::class)
                ->report($actor,$companyId,$hallId,$loanId);
            if((int)$snapshot['incidentReports']<1)
                throw new ConflictHttpException('Only a documented abnormal case may use non-clean closure.');
            if(count($snapshot['closureBlockers'])>0)
                throw new ConflictHttpException('Physical or financial final reconciliation remains open.');
            if(!DB::table('hall_inventory_nonclean_writeoff_decisions as d')
                ->join('hall_inventory_nonclean_writeoff_proposals as p',function($q):void {
                    $q->on('p.company_id','=','d.company_id')->on('p.id','=','d.proposal_id');
                })->where('d.company_id',$companyId)->where('d.loan_id',$loanId)
                ->where('d.decision','approved')->exists())
                throw new ConflictHttpException('Non-clean loss requires a recorded independently approved disposition.');
            if(DB::table('hall_inventory_nonclean_writeoff_decisions')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('decided_by',$actor->getKey())->exists())
                throw new AuthorizationException('Physical disposition reviewer cannot independently close the loan.');
            $fingerprint=hash('sha256',json_encode([
                $companyId,$hallId,$loanId,$snapshot['items'],$snapshot['obligations'],
                $snapshot['incidentReports'],$snapshot['unreviewedIncidents']
            ],JSON_THROW_ON_ERROR));
            $number=1+(int)DB::table('hall_inventory_nonclean_final_closures')
                ->where('company_id',$companyId)->max('receipt_number');
            $pdf=$this->renderPdf($companyId,$hallId,$loanId,$number,$fingerprint,$snapshot);
            $storagePath='private/hall-inventory-nonclean-final/'.$companyId.'/'.$id.'.pdf';
            if(!Storage::disk('local')->put($storagePath,$pdf))
                throw new ConflictHttpException('Numbered final document storage failed.');
            try {
                DB::table('hall_inventory_nonclean_final_closures')->insert([
                    'id'=>$id,'company_id'=>$companyId,'hall_id'=>$hallId,'loan_id'=>$loanId,
                    'receipt_number'=>$number,'reconciliation_sha256'=>$fingerprint,
                    'pdf_sha256'=>hash('sha256',$pdf),'pdf_size'=>strlen($pdf),
                    'pdf_storage_path'=>$storagePath,'closed_by'=>$actor->getKey()
                ]);
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.nonclean_final_closed',
                    'entity_type'=>'hall_inventory_nonclean_final_closure','entity_id'=>$id,
                    'before_values'=>json_encode(['closed'=>false],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['closed'=>true,'number'=>$number,'sha256'=>$fingerprint],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['loanId'=>$loanId,'hallId'=>$hallId,'moneyInvented'=>false],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e){Storage::disk('local')->delete($storagePath);throw $e;}
            return ['id'=>$id,'receiptNumber'=>$number];
        },3);
    }

    public function receiptPdf(User $actor,string $companyId,string $hallId,string $loanId):string {
        $this->gate();$this->scoped($actor,$companyId,$hallId);
        // A valid owner of another hall cannot probe a loan's private certificate.
        if(!DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$companyId)->where('id',$loanId)
            ->where('lending_hall_id',$hallId)->where('direction','external_out')->exists())
            throw new AuthorizationException('Original lending hall loan access required.');
        $row=DB::table('hall_inventory_nonclean_final_closures')
            ->where('company_id',$companyId)->where('hall_id',$hallId)
            ->where('loan_id',$loanId)->first();
        if(!$row)throw new ConflictHttpException('No numbered non-clean document recorded.');
        $bytes=$this->bytes($row);
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.nonclean_final_pdf_downloaded',
            'entity_type'=>'hall_inventory_nonclean_final_closure','entity_id'=>$row->id,
            'before_values'=>'{}',
            'after_values'=>json_encode(['verifiedSha256'=>$row->pdf_sha256],JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId,'hallId'=>$hallId],JSON_THROW_ON_ERROR)
        ]);
        return $bytes;
    }

    private function renderPdf(string $companyId,string $hallId,string $loanId,int $number,string $hash,array $snapshot):string {
        $lines=['VENUEDESK - FINAL NON-CLEAN OUTSIDE OWNED LOAN',
            'Immutable receipt NCL-'.$number,'Company '.$companyId,'Hall '.$hallId,
            'Loan '.$loanId,'Reconciliation SHA256 '.$hash,
            'Inventory disposition and finance are separately independently authorized'];
        foreach($snapshot['items'] as $item)
            $lines[]='Item '.$item['itemId'].' dispatched '.$item['dispatched'].
                ' good '.$item['good'].' damaged '.$item['damaged'].
                ' missing retired '.$item['writtenOffMissing'].
                ' damaged retired '.$item['writtenOffDamaged'];
        foreach($snapshot['obligations'] as $obligationId=>$money)
            $lines[]='Obligation '.$obligationId.' '.$money['currency'].
                ' paid '.$money['paidMinor'].' waived '.$money['waivedMinor'].
                ' remaining '.$money['remainingMinor'];
        $pages=array_chunk($lines,45);$font=3+count($pages)*2;$objects=[
            1=>'<< /Type /Catalog /Pages 2 0 R >>'];
        $pageIds=[];foreach($pages as $i=>$linesOnPage)$pageIds[]=3+$i*2;
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',array_map(
            static fn(int $n):string=>$n.' 0 R',$pageIds)).'] /Count '.count($pages).' >>';
        foreach($pages as $i=>$pageLines){
            $page=3+$i*2;$stream=$page+1;
            $commands="BT /F1 9 Tf 30 775 Td 14 TL\n";
            foreach($pageLines as $line){
                $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line);
                $commands.='('.$escaped.") Tj T*\n";
            }
            $commands.="ET\n";
            $objects[$page]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 '.$font.' 0 R >> >> /Contents '.$stream.' 0 R >>';
            $objects[$stream]='<< /Length '.strlen($commands)." >>\nstream\n".$commands."endstream";
        }
        $objects[$font]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects,SORT_NUMERIC);$pdf="%PDF-1.4\n";$offsets=[0];
        foreach($objects as $i=>$part){$offsets[$i]=strlen($pdf);$pdf.=$i." 0 obj\n".$part."\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 ".($font+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=$font;$i++)$pdf.=sprintf('%010d 00000 n ', $offsets[$i])."\n";
        return $pdf."trailer\n<< /Size ".($font+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}