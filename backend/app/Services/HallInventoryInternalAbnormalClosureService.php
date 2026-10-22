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
 * ADR-0009 Option A: separate source owner/admin write-off authorization and
 * numbered immutable INTERNAL abnormal final certificate. No company journal.
 */
final class HallInventoryInternalAbnormalClosureService
{
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $transition) {}
    private function gate(bool $close):void {
        foreach(['loan_drafts_enabled','loan_returns_enabled','loan_return_acceptance_enabled',
            'internal_discrepancy_enabled','internal_physical_finding_enabled',
            'internal_loss_obligation_enabled','internal_loss_settlement_enabled',
            'internal_abnormal_writeoff_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new ConflictHttpException('Internal abnormal write-off is not released.');
        if($close&&!config('inventory_controls.internal_abnormal_closure_enabled'))
            throw new ConflictHttpException('Internal abnormal final closure is not released.');
    }
    private function state(object $position):array {
        return ['ownedUsable'=>(int)$position->owned_usable,
            'ownedOnLoan'=>(int)$position->owned_on_loan,
            'ownedDamaged'=>(int)$position->owned_damaged,
            'borrowedUsable'=>(int)$position->borrowed_usable,
            'borrowedTransit'=>(int)$position->borrowed_transit,
            'borrowedDamaged'=>(int)$position->borrowed_damaged];
    }
    private function findings(string $companyId,string $sourceHallId,string $loanId):array {
        $rows=DB::table('hall_inventory_internal_physical_findings as f')
            ->join('hall_inventory_internal_physical_finding_decisions as d',function($j):void {
                $j->on('d.company_id','=','f.company_id')->on('d.finding_id','=','f.id');
            })->where('f.company_id',$companyId)->where('f.source_hall_id',$sourceHallId)
            ->where('f.loan_id',$loanId)->where('d.decision','confirmed_outstanding')
            ->get(['f.id','f.source_item_id','f.quantity']);
        return $rows->reject(fn($r):bool=>DB::table('hall_inventory_internal_finding_corrections')
            ->where('company_id',$companyId)->where('finding_id',$r->id)->exists())->values()->all();
    }


    private function verifyPrivateProof(object $record,string $pathField,string $sizeField,
        string $digestField):void {
        $path=(string)$record->{$pathField};
        $storage=Storage::disk('local');
        if(!$storage->exists($path))
            throw new ConflictHttpException('Original signed inventory or financial evidence is missing.');
        $bytes=$storage->get($path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$record->{$sizeField}||
            !hash_equals((string)$record->{$digestField},hash('sha256',$bytes)))
            throw new ConflictHttpException('Original signed inventory or financial evidence is corrupted.');
    }
    /**
     * Per-original-loan physical custody is not the aggregate hall position:
     * other live loans may legitimately share the same catalog item.
     * Fail closed on transit, inconsistent immutable returns or missing proof.
     */
    private function exactLoanWriteoff(User $actor,string $companyId,string $sourceHallId,
        string $loanId,array $findings):array {
        $lines=app(HallInventoryInternalLoanReturnReview::class)
            ->abnormalSourceLines($actor,$companyId,$sourceHallId,$loanId);
        // Re-check original independent signatures before any irreversible
        // write-off; a once-reviewed but subsequently missing file is not proof.
        app(HallInventoryInternalDiscrepancyService::class)
            ->history($actor,$companyId,$sourceHallId,$loanId,true);
        app(HallInventoryInternalPhysicalFindingService::class)
            ->history($actor,$companyId,$sourceHallId,$loanId,true);
        foreach([
            ['hall_inventory_loan_dispatches','loan_id'],
            ['hall_inventory_internal_loan_receipts','loan_id']
        ] as [$table,$loanKey]) {
            $signed=DB::table($table)->where('company_id',$companyId)
                ->where($loanKey,$loanId)->first();
            if(!$signed)throw new ConflictHttpException('Original independently signed loan evidence is absent.');
            $this->verifyPrivateProof($signed,'evidence_storage_path','evidence_size','evidence_sha256');
        }
        if($lines===[])throw new ConflictHttpException('Original loan physical custody is absent.');
        $confirmed=[];
        foreach($findings as $finding) {
            $item=(string)$finding->source_item_id;
            $confirmed[$item]=($confirmed[$item]??0)+(int)$finding->quantity;
        }
        $outstanding=0;
        foreach($lines as $line) {
            $item=(string)$line['itemId'];
            $remaining=(int)$line['notReturned']+(int)$line['unresolved'];
            if((bool)$line['integrityWarning']||(int)$line['inTransit']!==0||
                $remaining<0||(int)$line['unsettledPhysical']!==$remaining||
                ($confirmed[$item]??0)!==$remaining)
                throw new ConflictHttpException('Confirmed findings must exactly cover this original loan, without pending transit.');
            $outstanding+=$remaining;
            unset($confirmed[$item]);
        }
        if($confirmed!==[]||$outstanding<1)
            throw new ConflictHttpException('Confirmed write-off has unbound or zero original-loan units.');
        $byItem=[];
        foreach($findings as $finding) {
            $item=(string)$finding->source_item_id;
            $byItem[$item]=($byItem[$item]??0)+(int)$finding->quantity;
        }
        ksort($byItem,SORT_STRING);
        return $byItem;
    }
    private function commercial(string $companyId,string $loanId):array {
        $obligations=DB::table('hall_inventory_internal_loss_obligations as o')
            ->join('hall_inventory_internal_loss_obligation_decisions as d',function($j):void {
                $j->on('d.company_id','=','o.company_id')->on('d.obligation_id','=','o.id');
            })->where('o.company_id',$companyId)->where('o.loan_id',$loanId)
            ->where('d.decision','approved')->get(['o.id','o.currency','o.amount_minor']);
        $currencies=array_values(array_unique($obligations->pluck('currency')->all()));
        $settled=0;
        if($obligations->isNotEmpty()) {
            $verified=DB::table('hall_inventory_internal_loss_settlements as s')
                ->join('hall_inventory_internal_loss_settlement_reviews as r',function($j):void {
                    $j->on('r.company_id','=','s.company_id')->on('r.settlement_id','=','s.id');
                })->where('s.company_id',$companyId)->whereIn('s.obligation_id',$obligations->pluck('id'))
                ->where('r.decision','verified')
                ->get(['s.amount_minor','s.proof_path','s.proof_sha256','s.proof_size']);
            foreach($verified as $s) {
                $this->verifyPrivateProof($s,'proof_path','proof_size','proof_sha256');
                $settled+=(int)$s->amount_minor;
            }
        }
        return ['obligations'=>$obligations,'currencies'=>$currencies,
            'amount'=>(int)$obligations->sum('amount_minor'),'settled'=>$settled];
    }
    public function authorize(User $actor,string $companyId,string $sourceHallId,string $loanId,
        string $authorizationId,string $reason):string {
        $this->gate(false);
        foreach([$companyId,$sourceHallId,$loanId,$authorizationId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact write-off UUIDs required.');
        if(mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Write-off authorization reason is required.');
        $reason=trim($reason);
        return DB::transaction(function()use($actor,$companyId,$sourceHallId,$loanId,
            $authorizationId,$reason):string {
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='internal'||(string)$loan->lending_hall_id!==$sourceHallId)
                throw new AuthorizationException('Write-off requires the source-hall internal loan.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current source-hall owner/admin required.');
            $findings=$this->findings($companyId,$sourceHallId,$loanId);
            if($findings===[])throw new ConflictHttpException('No confirmed physical findings support a write-off.');
            $byItem=$this->exactLoanWriteoff($actor,$companyId,$sourceHallId,$loanId,$findings);
            $authorized=(int)array_sum($byItem);
            $positions=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                ->where('hall_id',$sourceHallId)->whereIn('item_id',array_keys($byItem))
                ->get(['item_id','owned_on_loan'])->keyBy('item_id');
            foreach($byItem as $itemId=>$quantity)
                if(!$positions->has($itemId)||(int)$positions[$itemId]->owned_on_loan<$quantity)
                    throw new ConflictHttpException('Original loan write-off exceeds physically posted source custody.');
            $commercial=$this->commercial($companyId,$loanId);
            if($commercial['obligations']->isEmpty()||count($commercial['currencies'])!==1)
                throw new ConflictHttpException('One independently approved obligation currency is required.');
            if($commercial['settled']<$commercial['amount'])
                throw new ConflictHttpException('Verified settlement evidence does not cover the approved obligation.');
            $actorId=(int)$actor->getKey();
            $involved=DB::table('hall_inventory_internal_physical_findings')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('proposed_by',$actorId)->exists() ||
                DB::table('hall_inventory_internal_loss_obligations')
                    ->where('company_id',$companyId)->where('loan_id',$loanId)
                    ->where('proposed_by',$actorId)->exists() ||
                DB::table('hall_inventory_internal_loss_settlements as s')
                    ->join('hall_inventory_internal_loss_obligations as o',function($j):void {
                        $j->on('o.company_id','=','s.company_id')->on('o.id','=','s.obligation_id');
                    })->where('s.company_id',$companyId)->where('o.loan_id',$loanId)
                    ->where('s.recorded_by',$actorId)->exists() ||
                DB::table('hall_inventory_internal_loss_settlement_reviews as r')
                    ->join('hall_inventory_internal_loss_settlements as s',function($j):void {
                        $j->on('s.company_id','=','r.company_id')->on('s.id','=','r.settlement_id');
                    })->join('hall_inventory_internal_loss_obligations as o',function($j):void {
                        $j->on('o.company_id','=','s.company_id')->on('o.id','=','s.obligation_id');
                    })->where('s.company_id',$companyId)->where('o.loan_id',$loanId)
                    ->where('r.reviewed_by',$actorId)->exists();
            if($involved)
                throw new AuthorizationException('Finding, obligation, settlement recorder or settlement reviewer cannot authorize the write-off.');
            $fingerprint=hash('sha256',json_encode([$companyId,$sourceHallId,$loanId,
                $authorized,$reason,$actorId],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_internal_abnormal_writeoff_authorizations')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old) {
                if((string)$old->id!==$authorizationId||
                    (int)$old->authorized_by!==$actorId||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Internal write-off already has a different immutable authorization.');
                return $authorizationId;
            }
            DB::table('hall_inventory_internal_abnormal_writeoff_authorizations')->insert([
                'id'=>$authorizationId,'company_id'=>$companyId,'loan_id'=>$loanId,
                'source_hall_id'=>$sourceHallId,'borrowing_hall_id'=>$loan->borrowing_hall_id,
                'authorized_quantity'=>$authorized,'reason'=>$reason,'fingerprint'=>$fingerprint,
                'authorized_by'=>$actorId
            ]);
            $this->audit($actor,$companyId,'authorized',$authorizationId,$loanId,
                ['authorizedQuantity'=>$authorized,'journalPosted'=>false,'stockRetiredByThisLayer'=>false]);
            return $authorizationId;
        },3);
    }
    public function close(User $actor,string $companyId,string $sourceHallId,string $loanId,
        string $closureId):array {
        $this->gate(true);
        foreach([$companyId,$sourceHallId,$loanId,$closureId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact closure UUIDs required.');
        return DB::transaction(function()use($actor,$companyId,$sourceHallId,$loanId,$closureId):array {
            // Company row serializes the IAC number across *different* source halls.
            $company=DB::table('companies')->where('id',$companyId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$company)throw new AuthorizationException('Inactive company cannot close an original loan.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='internal'||(string)$loan->lending_hall_id!==$sourceHallId)
                throw new AuthorizationException('Closure requires the source-hall internal loan.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current source-hall owner/admin required.');
            $old=DB::table('hall_inventory_internal_abnormal_closures')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old) {
                if((string)$old->id!==$closureId||(int)$old->closed_by!==(int)$actor->getKey())
                    throw new ConflictHttpException('Loan already has a different immutable abnormal closure.');
                $this->verifyPdf($old);
                return $this->summary($old);
            }
            $authorization=DB::table('hall_inventory_internal_abnormal_writeoff_authorizations')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->lockForUpdate()->first();
            if(!$authorization)throw new ConflictHttpException('No independent write-off authorization exists.');
            $findings=$this->findings($companyId,$sourceHallId,$loanId);
            $byItem=$this->exactLoanWriteoff($actor,$companyId,$sourceHallId,$loanId,$findings);
            if(array_sum($byItem)!==(int)$authorization->authorized_quantity)
                throw new ConflictHttpException('Approved write-off no longer matches original custody.');
            foreach($byItem as $itemId=>$quantity) {
                $position=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                    ->where('hall_id',$sourceHallId)->where('item_id',$itemId)->lockForUpdate()->first();
                if(!$position||(int)$position->owned_on_loan<$quantity)
                    throw new ConflictHttpException('Original loan write-off exceeds current shared source custody.');
                $before=$this->state($position);
                $after=$this->transition->apply($before,'writeoff_owned_on_loan',$quantity);
                $revision=(int)$position->revision;
                $updated=DB::table('hall_inventory_positions')->where('id',$position->id)
                    ->where('company_id',$companyId)->where('hall_id',$sourceHallId)
                    ->where('item_id',$itemId)->where('revision',$revision)->update([
                        'owned_on_loan'=>$after['ownedOnLoan'],'revision'=>$revision+1,
                        'updated_at'=>now()
                    ]);
                if($updated!==1)throw new ConflictHttpException('Concurrent custody changed.');
                DB::table('hall_inventory_movement_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'hall_id'=>$sourceHallId,
                    'item_id'=>$itemId,'position_id'=>$position->id,
                    'event_kind'=>'writeoff_owned_on_loan','quantity'=>$quantity,
                    'revision_before'=>$revision,'revision_after'=>$revision+1,
                    'request_fingerprint'=>hash('sha256',$closureId.$itemId.$quantity),
                    'reason'=>'ADR-0009 Option A authorized internal abnormal write-off',
                    'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                    'actor_id'=>$actor->getKey()
                ]);
            }
            $commercial=$this->commercial($companyId,$loanId);
            $currency=(string)($commercial['currencies'][0]??'');
            $serialSeq=((int)DB::table('hall_inventory_internal_abnormal_closures')
                ->where('company_id',$companyId)->max('serial_seq'))+1;
            $serial='IAC-'.$serialSeq;
            $pdf=$this->pdf($serial,$loanId,$sourceHallId,$currency,$commercial['amount'],
                (int)$authorization->authorized_quantity);
            $sha=hash('sha256',$pdf);$size=strlen($pdf);
            $path='private/hall-internal-abnormal/'.$companyId.'/'.$loanId.'/'.$closureId.'.pdf';
            $disk=Storage::disk('local');
            if($disk->exists($path))throw new ConflictHttpException('Closure PDF key already occupied.');
            if(!$disk->put($path,$pdf))throw new ConflictHttpException('Closure PDF could not be stored.');
            try {
                DB::table('hall_inventory_internal_abnormal_closures')->insert([
                    'id'=>$closureId,'company_id'=>$companyId,'loan_id'=>$loanId,
                    'source_hall_id'=>$sourceHallId,'borrowing_hall_id'=>$loan->borrowing_hall_id,
                    'authorization_id'=>$authorization->id,'serial_seq'=>$serialSeq,
                    'serial_number'=>$serial,'currency'=>$currency,
                    'amount_minor'=>(int)$commercial['amount'],
                    'writeoff_quantity'=>(int)$authorization->authorized_quantity,
                    'pdf_sha256'=>$sha,'pdf_size'=>$size,'pdf_path'=>$path,
                    'readiness'=>json_encode(['option'=>'A','ledgerPosted'=>false,
                        'settledAmountMinor'=>$commercial['settled'],'journalPosted'=>false],
                        JSON_THROW_ON_ERROR),'closed_by'=>$actor->getKey()
                ]);
                $this->audit($actor,$companyId,'closed',$closureId,$loanId,
                    ['serial'=>$serial,'writeoffQuantity'=>$authorization->authorized_quantity,
                     'amountMinor'=>$commercial['amount'],'currency'=>$currency,
                     'journalPosted'=>false,'pdfSha256'=>$sha]);
            }catch(\Throwable $e){$disk->delete($path);throw $e;}
            return $this->summary(DB::table('hall_inventory_internal_abnormal_closures')
                ->where('id',$closureId)->first());
        },3);
    }
    private function pdf(string $serial,string $loanId,string $hallId,string $currency,
        int $amount,int $quantity):string {
        $lines=['VenueDesk - Internal Abnormal Final Certificate','Certificate: '.$serial,
            'Source hall: '.$hallId,'Original internal loan: '.$loanId,
            'Authorized owned-on-loan units written off: '.$quantity,
            'Approved obligation: '.$amount.' '.$currency.' (minor units)',
            'Settlement: independently verified evidence; no company-level journal (ADR-0009 Option A).',
            'No unconfirmed stock was retired. No production activation.',
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
        foreach($objects as $i=>$object){$offsets[$i]=strlen($pdf);$pdf.=$i." 0 obj\n".$object."\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
        for($i=1;$i<=5;$i++)$pdf.=sprintf('%010d 00000 n ',$offsets[$i])."\n";
        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
    private function summary(object $row):array {
        return ['id'=>(string)$row->id,'loanId'=>(string)$row->loan_id,
            'serialNumber'=>(string)$row->serial_number,'currency'=>(string)$row->currency,
            'amountMinor'=>(int)$row->amount_minor,
            'writeoffQuantity'=>(int)$row->writeoff_quantity,
            'pdfSha256'=>(string)$row->pdf_sha256,'pdfSize'=>(int)$row->pdf_size,
            'closedBy'=>(int)$row->closed_by,'closedAt'=>(string)$row->created_at,
            'journalPosted'=>false];
    }
    private function verifyPdf(object $row):string {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->pdf_path))throw new ConflictHttpException('Closure PDF is missing.');
        $bytes=$disk->get((string)$row->pdf_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->pdf_size||
            !hash_equals((string)$row->pdf_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Closure PDF integrity verification failed.');
        return $bytes;
    }
    public function download(User $actor,string $companyId,string $sourceHallId,
        string $closureId):array {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Internal abnormal certificate is source-hall restricted.');
        $row=DB::table('hall_inventory_internal_abnormal_closures')
            ->where('company_id',$companyId)->where('source_hall_id',$sourceHallId)
            ->where('id',$closureId)->first();
        if(!$row)throw new ConflictHttpException('Internal abnormal certificate was not found.');
        $bytes=$this->verifyPdf($row);
        return ['bytes'=>$bytes,
            'mime'=>'application/pdf',
            'name'=>'internal-abnormal-'.$row->serial_number.'.pdf'];
    }
    private function audit(User $actor,string $companyId,string $action,string $id,
        string $loanId,array $data):void {
        DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.internal_abnormal_'.$action,
            'entity_type'=>'hall_inventory_internal_abnormal_closure','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId],JSON_THROW_ON_ERROR)]);
    }
}
