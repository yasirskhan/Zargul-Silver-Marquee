<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
                'b.created_at','b.returned_by','a.id as acceptance_id']);
        if($rows->isEmpty())return [];
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
                static fn($line):array=>[
                    'sourceItemId'=>(string)$line->source_item_id,
                    'destinationItemId'=>(string)$line->item_id,
                    'name'=>(string)$line->item_name_snapshot,
                    'unit'=>(string)$line->unit_snapshot,
                    'sentQuantity'=>(int)$line->quantity,
                    'goodQuantity'=>$line->good_quantity===null?null:(int)$line->good_quantity,
                    'damagedQuantity'=>$line->damaged_quantity===null?null:(int)$line->damaged_quantity,
                    'unresolvedQuantity'=>$line->unresolved_quantity===null?null:(int)$line->unresolved_quantity
                ])->all()
        ])->all();
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
