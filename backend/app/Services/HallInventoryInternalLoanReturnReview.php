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
 * Read-only scoped return custody and unresolved source condition review.
 * Neither a dispatched return nor a counted return closes the loan.
 */
final class HallInventoryInternalLoanReturnReview
{
    public function __construct(private readonly TenantAccess $access) {}

    /** @return array<int,array<string,mixed>> */
    public function destinationLines(User $actor,string $companyId,string $hallId,
        string $loanId):array
    {
        $this->gated();
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||!Str::isUuid($loanId))
            throw new InvalidArgumentException('Invalid loan return scope.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Return inventory viewing denied.');
        $draft=DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$companyId)->where('id',$loanId)
            ->where('direction','internal')->where('borrowing_hall_id',$hallId)->first();
        if(!$draft)throw new ConflictHttpException('Original borrowing hall does not own this return view.');
        $receipt=DB::table('hall_inventory_internal_loan_receipts')
            ->where('company_id',$companyId)->where('loan_id',$loanId)
            ->where('receiving_hall_id',$hallId)->first(['id']);
        if(!$receipt)throw new ConflictHttpException('No independently received borrowed custody.');
        $lines=DB::table('hall_inventory_internal_loan_receipt_lines as rl')
            ->join('hall_inventory_loan_draft_lines as dl',function($join):void {
                $join->on('dl.company_id','=','rl.company_id')
                    ->on('dl.item_id','=','rl.source_item_id');
            })
            ->where('rl.company_id',$companyId)
            ->where('rl.receipt_id',$receipt->id)
            ->where('dl.loan_id',$loanId)
            ->orderBy('rl.source_item_id')
            ->get(['rl.source_item_id','rl.item_id','rl.quantity',
                'dl.item_name_snapshot','dl.unit_snapshot']);
        if($lines->isEmpty())throw new ConflictHttpException('Original receipt has no custody lines.');
        $shipped=DB::table('hall_inventory_internal_loan_return_lines as l')
            ->join('hall_inventory_internal_loan_return_batches as b',function($j):void {
                $j->on('b.company_id','=','l.company_id')->on('b.id','=','l.return_batch_id');
            })
            ->where('b.company_id',$companyId)->where('b.receipt_id',$receipt->id)
            ->select('l.source_item_id',DB::raw('SUM(l.quantity) as total'))
            ->groupBy('l.source_item_id')->pluck('total','l.source_item_id');
        $positions=DB::table('hall_inventory_positions')
            ->where('company_id',$companyId)->where('hall_id',$hallId)
            ->whereIn('item_id',$lines->pluck('item_id')->all())
            ->get(['item_id','borrowed_usable','revision'])->keyBy('item_id');
        return $lines->map(function($line) use($positions,$shipped):array {
            $sent=(int)($shipped->get((string)$line->source_item_id)??0);
            $received=(int)$line->quantity;
            $position=$positions->get((string)$line->item_id);
            return [
                'sourceItemId'=>(string)$line->source_item_id,
                'destinationItemId'=>(string)$line->item_id,
                'name'=>(string)$line->item_name_snapshot,
                'unit'=>(string)$line->unit_snapshot,
                'receivedQuantity'=>$received,
                'alreadyReturnedQuantity'=>$sent,
                'returnableQuantity'=>max(0,min($received-$sent,
                    $position?(int)$position->borrowed_usable:0)),
                'revision'=>$position?(int)$position->revision:null
            ];
        })->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function sourceBatches(User $actor,string $companyId,string $hallId,
        int $limit=20):array
    {
        $this->gated();
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||
            $limit<1||$limit>20)
            throw new InvalidArgumentException('Invalid source return history query.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Only active source owners/admins may inspect return acknowledgments.');
        $rows=DB::table('hall_inventory_internal_loan_return_batches as b')
            ->leftJoin('hall_inventory_internal_loan_return_acceptances as a',function($j):void {
                $j->on('a.company_id','=','b.company_id')
                    ->on('a.return_batch_id','=','b.id');
            })
            ->where('b.company_id',$companyId)->where('b.source_hall_id',$hallId)
            ->orderByDesc('b.created_at')->orderByDesc('b.id')->limit($limit)
            ->get(['b.id','b.loan_id','b.destination_hall_id',
                'b.created_at','b.returned_by','a.id as acceptance_id',
                'b.evidence_storage_path as shipped_path','b.evidence_size as shipped_size',
                'b.evidence_sha256 as shipped_sha',
                'a.evidence_storage_path as accepted_path','a.evidence_size as accepted_size',
                'a.evidence_sha256 as accepted_sha']);
        if($rows->isEmpty())return [];
        // Fail closed before displaying any physical custody quantity if
        // either side's signed immutable PDF/photo has been lost or changed.
        // Restrict reads to server-derived private paths from this scoped query.
        $disk=Storage::disk('local');
        foreach($rows as $row) {
            foreach([
                ['path'=>$row->shipped_path,'size'=>$row->shipped_size,
                    'sha'=>$row->shipped_sha],
                ...($row->acceptance_id===null?[]:[[
                    'path'=>$row->accepted_path,'size'=>$row->accepted_size,
                    'sha'=>$row->accepted_sha
                ]])
            ] as $proof) {
                $path=(string)$proof['path'];
                if(!str_starts_with($path,'private/hall-loan-return')||
                    !$disk->exists($path))
                    throw new ConflictHttpException('Original signed return history proof missing.');
                $bytes=$disk->get($path);
                if(!is_string($bytes)||
                    strlen($bytes)!==(int)$proof['size']||
                    !hash_equals((string)$proof['sha'],hash('sha256',$bytes)))
                    throw new ConflictHttpException('Original signed return history proof integrity failed.');
            }
        }

        $lateRows=DB::table('hall_inventory_internal_late_returns')
            ->where('company_id',$companyId)->whereIn('return_batch_id',$rows->pluck('id')->all())
            ->get();
        $late=[];
        foreach($lateRows as $lateRow) {
            HallInventoryInternalLateReturnService::verifyRecordedProof($lateRow);
            $key=$lateRow->return_batch_id.'/'.$lateRow->source_item_id;
            $late[$key]['ids'][]=(string)$lateRow->id;
            $late[$key]['good']=($late[$key]['good']??0)+(int)$lateRow->good_quantity;
            $late[$key]['damaged']=($late[$key]['damaged']??0)+(int)$lateRow->damaged_quantity;
        }

        $all=DB::table('hall_inventory_internal_loan_return_lines as l')
            ->join('hall_inventory_internal_loan_return_batches as batch',function($j):void {
                $j->on('batch.company_id','=','l.company_id')
                    ->on('batch.id','=','l.return_batch_id');
            })
            ->join('hall_inventory_loan_draft_lines as d',function($j):void {
                $j->on('d.company_id','=','l.company_id')
                    ->on('d.loan_id','=','batch.loan_id')
                    ->on('d.item_id','=','l.source_item_id');
            })
            ->leftJoin('hall_inventory_internal_loan_return_acceptances as a',function($j):void {
                $j->on('a.company_id','=','l.company_id')
                    ->on('a.return_batch_id','=','l.return_batch_id');
            })
            ->leftJoin('hall_inventory_internal_loan_return_acceptance_lines as al',function($j):void {
                $j->on('al.company_id','=','l.company_id')
                    ->on('al.acceptance_id','=','a.id')
                    ->on('al.source_item_id','=','l.source_item_id');
            })
            ->where('l.company_id',$companyId)
            ->whereIn('l.return_batch_id',$rows->pluck('id')->all())
            ->get(['l.return_batch_id','l.source_item_id','l.item_id',
                'l.quantity','d.item_name_snapshot','d.unit_snapshot',
                'al.good_quantity','al.damaged_quantity','al.unresolved_quantity'])
            ->groupBy('return_batch_id');
        return $rows->map(fn($batch):array=>[
            'batchId'=>(string)$batch->id,
            'loanId'=>(string)$batch->loan_id,
            'destinationHallId'=>(string)$batch->destination_hall_id,
            'sentBy'=>(int)$batch->returned_by,
            'sentAt'=>(string)$batch->created_at,
            'state'=>$batch->acceptance_id!==null
                ? 'SOURCE_REVIEW_RECORDED_OUTSTANDING_CHECK_REQUIRED'
                : 'IN_TRANSIT_SOURCE_ACK_PENDING',
            'accepted'=>$batch->acceptance_id!==null,
            'lines'=>($all->get($batch->id)??collect())->map(
                fn($line):array=>[
                    'sourceItemId'=>(string)$line->source_item_id,
                    'destinationItemId'=>(string)$line->item_id,
                    'name'=>(string)$line->item_name_snapshot,
                    'unit'=>(string)$line->unit_snapshot,
                    'sentQuantity'=>(int)$line->quantity,
                    'goodQuantity'=>$line->good_quantity===null?null:(int)$line->good_quantity,
                    'damagedQuantity'=>$line->damaged_quantity===null?null:(int)$line->damaged_quantity,
                    'unresolvedQuantity'=>$line->unresolved_quantity===null?null:(int)$line->unresolved_quantity,
                    'lateProofIds'=>$late[$batch->id.'/'.$line->source_item_id]['ids']??[],
                    'lateGood'=>(int)($late[$batch->id.'/'.$line->source_item_id]['good']??0),
                    'lateDamaged'=>(int)($late[$batch->id.'/'.$line->source_item_id]['damaged']??0),
                    'remainingUnresolved'=>$line->unresolved_quantity===null?null:
                        (int)$line->unresolved_quantity-
                        (int)($late[$batch->id.'/'.$line->source_item_id]['good']??0)-
                        (int)($late[$batch->id.'/'.$line->source_item_id]['damaged']??0)
                ])->all()
        ])->all();
    }


    /**
     * Original-source-only read-only abnormal custody reconciliation.
     * Never treats damaged or disputed units as good, or incident testimony
     * as an approved financial obligation or stock disposition.
     */
    public function abnormalSourceLines(User $actor,string $companyId,string $hallId,string $loanId):array
    {
        $this->gated();
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||!Str::isUuid($loanId))
            throw new InvalidArgumentException('Invalid abnormal loan scope.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Current lending hall owner/admin required.');
        $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
            ->where('id',$loanId)->where('lending_hall_id',$hallId)
            ->where('direction','internal')->first();
        if(!$loan)throw new AuthorizationException('Original internal loan scope required.');
        $dispatch=DB::table('hall_inventory_loan_dispatches')
            ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
        if(!$dispatch)throw new ConflictHttpException('No physically dispatched original custody.');
        $lines=DB::table('hall_inventory_loan_dispatch_lines')
            ->where('company_id',$companyId)->where('dispatch_id',$dispatch->id)
            ->orderBy('item_id')->get(['item_id','quantity']);
        if($lines->isEmpty())throw new ConflictHttpException('No original custody lines.');
        $returned=DB::table('hall_inventory_internal_loan_return_acceptance_lines as l')
            ->join('hall_inventory_internal_loan_return_acceptances as a',function($j):void {
                $j->on('a.company_id','=','l.company_id')->on('a.id','=','l.acceptance_id');
            })->join('hall_inventory_internal_loan_return_batches as b',function($j):void {
                $j->on('b.company_id','=','a.company_id')->on('b.id','=','a.return_batch_id');
            })->where('l.company_id',$companyId)->where('b.loan_id',$loanId)
            ->select('l.source_item_id')->selectRaw('SUM(l.good_quantity) as good')
            ->selectRaw('SUM(l.damaged_quantity) as damaged')
            ->selectRaw('SUM(l.unresolved_quantity) as unresolved')
            ->groupBy('l.source_item_id')->get()->keyBy('source_item_id');
        $later=DB::table('hall_inventory_internal_late_returns')
            ->where('company_id',$companyId)->where('loan_id',$loanId)
            ->select('source_item_id')->selectRaw('SUM(good_quantity) as good')
            ->selectRaw('SUM(damaged_quantity) as damaged')
            ->groupBy('source_item_id')->get()->keyBy('source_item_id');
        $laterProofs=DB::table('hall_inventory_internal_late_returns')
            ->where('company_id',$companyId)->where('loan_id',$loanId)->get();
        foreach($laterProofs as $laterProof)
            HallInventoryInternalLateReturnService::verifyRecordedProof($laterProof);
        $pending=DB::table('hall_inventory_internal_loan_return_lines as l')
            ->join('hall_inventory_internal_loan_return_batches as b',function($j):void {
                $j->on('b.company_id','=','l.company_id')->on('b.id','=','l.return_batch_id');
            })->leftJoin('hall_inventory_internal_loan_return_acceptances as a',function($j):void {
                $j->on('a.company_id','=','b.company_id')->on('a.return_batch_id','=','b.id');
            })->where('l.company_id',$companyId)->where('b.loan_id',$loanId)
            ->whereNull('a.id')->select('l.source_item_id')
            ->selectRaw('SUM(l.quantity) as pending')->groupBy('l.source_item_id')
            ->pluck('pending','source_item_id');
        $incidents=DB::table('hall_inventory_loan_incident_reports as r')
            ->leftJoin('hall_inventory_loan_incident_decisions as d',function($j):void {
                $j->on('d.company_id','=','r.company_id')->on('d.report_id','=','r.id');
            })->where('r.company_id',$companyId)->where('r.loan_id',$loanId)
            ->select('r.source_item_id')->selectRaw('COUNT(*) as reported')
            ->selectRaw("SUM(CASE WHEN d.decision = 'substantiated' THEN 1 ELSE 0 END) as substantiated")
            ->selectRaw("SUM(CASE WHEN r.kind = 'missing' AND d.decision = 'substantiated' THEN r.quantity ELSE 0 END) as substantiated_missing")
            ->selectRaw("SUM(CASE WHEN r.kind = 'dispute' AND d.decision = 'substantiated' THEN r.quantity ELSE 0 END) as substantiated_dispute")
            ->selectRaw("SUM(CASE WHEN r.kind IN ('missing','dispute') AND (d.id IS NULL OR d.decision = 'requires_more_evidence') THEN r.quantity ELSE 0 END) as pending_discrepancy_review")
            ->groupBy('r.source_item_id')->get()->keyBy('source_item_id');
        // Mapped borrower-item book corroboration is an AGGREGATE custody
        // signal shared by any loan using that catalog item; NOT per-loan\n        // attribution, a signed admission or a return condition.
        $borrowerReceipt=DB::table('hall_inventory_internal_loan_receipts')
            ->where('company_id',$companyId)->where('loan_id',$loanId)
            ->first(['id','receiving_hall_id']);
        if(!$borrowerReceipt)
            throw new ConflictHttpException('Original destination custody receipt missing.');
        $mapped=DB::table('hall_inventory_internal_loan_receipt_lines')
            ->where('company_id',$companyId)->where('receipt_id',$borrowerReceipt->id)
            ->get(['source_item_id','item_id'])->keyBy('source_item_id');
        $destinationPositions=DB::table('hall_inventory_positions')
            ->where('company_id',$companyId)->where('hall_id',$borrowerReceipt->receiving_hall_id)
            ->whereIn('item_id',$mapped->pluck('item_id')->all())
            ->get(['item_id','borrowed_usable','borrowed_transit','borrowed_damaged'])
            ->keyBy('item_id');
        // Approved documents are not enough: the immutable decision must
        // reference an exact owned-damage retirement movement. Reject any
        // privileged cross-kind/quantity/actor misbinding rather than showing a
        // fabricated cleared stock balance.
        $approved=DB::table('hall_inventory_nonclean_writeoff_proposals as p')
            ->join('hall_inventory_nonclean_writeoff_decisions as d',function($j):void {
                $j->on('d.company_id','=','p.company_id')
                  ->on('d.proposal_id','=','p.id');
            })->leftJoin('hall_inventory_movement_events as m',function($j):void {
                $j->on('m.company_id','=','d.company_id')
                  ->on('m.hall_id','=','d.hall_id')
                  ->on('m.item_id','=','d.item_id')
                  ->on('m.id','=','d.movement_id');
            })->where('p.company_id',$companyId)->where('p.hall_id',$hallId)
            ->where('p.loan_id',$loanId)->where('p.kind','damage')
            ->where('d.decision','approved')
            ->get(['p.id','p.item_id','p.quantity','d.fingerprint','d.decided_by',
                'm.event_kind','m.quantity as movement_quantity',
                'm.reason as movement_reason','m.actor_id as movement_actor',
                'm.request_fingerprint as movement_fingerprint']);
        $retired=[];
        foreach($approved as $w) {
            if($w->event_kind!=='writeoff_owned_damaged'||
                (int)$w->movement_quantity!==(int)$w->quantity||
                (int)$w->movement_actor!==(int)$w->decided_by||
                (string)$w->movement_reason!=='Independently authorized non-clean loss disposition '.$w->id||
                !hash_equals((string)$w->fingerprint,(string)$w->movement_fingerprint))
                throw new ConflictHttpException('Approved physical retirement has inconsistent immutable movement provenance.');
            $retired[(string)$w->item_id]=($retired[(string)$w->item_id]??0)+(int)$w->quantity;
        }
        $result=[];
        foreach($lines as $line) {
            $id=(string)$line->item_id;$total=(int)$line->quantity;
            $a=$returned->get($id);$follow=$later->get($id);
            $lateGood=(int)($follow->good??0);$lateDamaged=(int)($follow->damaged??0);
            $good=(int)($a->good??0)+$lateGood;
            $damaged=(int)($a->damaged??0)+$lateDamaged;
            $unresolved=(int)($a->unresolved??0)-$lateGood-$lateDamaged;
            $inTransit=(int)($pending[$id]??0);
            $notSent=$total-$good-$damaged-$unresolved-$inTransit;
            $retiredDamage=(int)($retired[$id]??0);
            $integrityWarning=$notSent<0||$unresolved<0||$retiredDamage>$damaged;
            $map=$mapped->get($id);
            $destBook=$map?$destinationPositions->get((string)$map->item_id):null;
            if(!$destBook)
                throw new ConflictHttpException('Original borrower book line missing from custody reconciliation.');
            $incident=$incidents->get($id);
            // Incident decisions are original-hall witness findings, not
            // a stock movement or a borrower admission. Keep disputed
            // evidence separate from true unreturned physical quantity.
            $substantiatedMissing=(int)($incident->substantiated_missing??0);
            $substantiatedDispute=(int)($incident->substantiated_dispute??0);
            $pendingReview=(int)($incident->pending_discrepancy_review??0);
            $unsettledPhysical=max(0,$total-$good-$damaged);
            $integrityWarning=$integrityWarning||
                $substantiatedMissing>$unsettledPhysical||
                $substantiatedDispute>$unsettledPhysical;
            $result[]=['itemId'=>$id,'dispatched'=>$total,'good'=>$good,
                'damaged'=>$damaged,'approvedDamagedRetirement'=>$retiredDamage,
                'remainingDamagedForDisposition'=>max(0,$damaged-$retiredDamage),
                'unresolved'=>$unresolved,'inTransit'=>$inTransit,
                'notReturned'=>max(0,$notSent),'integrityWarning'=>$integrityWarning,
                'substantiatedMissingEvidence'=>$substantiatedMissing,
                'substantiatedDisputeEvidence'=>$substantiatedDispute,
                'pendingDiscrepancyReview'=>$pendingReview,
                'unsettledPhysical'=>$unsettledPhysical,
                'borrowerBookUsable'=>(int)$destBook->borrowed_usable,
                'borrowerBookTransit'=>(int)$destBook->borrowed_transit,
                'borrowerBookDamaged'=>(int)$destBook->borrowed_damaged,
                'incidentReports'=>(int)($incident->reported??0),
                'substantiatedReports'=>(int)($incident->substantiated??0),
                'closureBlocked'=>true,'moneyPosted'=>false];
        }
        return $result;
    }

    private function gated():void
    {
        if(!config('inventory_controls.loan_drafts_enabled')||
            !config('inventory_controls.loan_dispatch_enabled')||
            !config('inventory_controls.loan_receipt_enabled')||
            !config('inventory_controls.loan_returns_enabled'))
            throw new ConflictHttpException('Private loan return history is disabled.');
    }
}
