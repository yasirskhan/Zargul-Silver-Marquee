<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use App\Policies\FinanceAccessPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class HallInventoryFinancialRecoveryService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate(bool $posting):void
    {
        foreach(['loan_financial_claims_enabled','loan_partner_responses_enabled',
            'loan_authorized_obligations_enabled','loan_recoveries_enabled',
            'external_out_dispatch_enabled','external_out_return_enabled'] as $flag)
            if(!config('inventory_controls.'.$flag))
                throw new ConflictHttpException('Outside loss recovery workflow is disabled.');
        if($posting && config('finance_controls.posting_enabled')!==true)
            throw new ConflictHttpException('Financial journal release is OFF.');
    }

    private function ids(string ...$ids):void
    {
        foreach($ids as $id)if(!Str::isUuid($id))
            throw new InvalidArgumentException('Exact claim recovery UUID required.');
    }

    private function authorize(User $actor,string $companyId,string $hallId,bool $post):void
    {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        $policy=new FinanceAccessPolicy();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall)||
            !($post?$policy->post($actor,$companyId):$policy->view($actor,$companyId)))
            throw new AuthorizationException('Live owner/admin hall and finance custody access required.');
    }

    private function evidence(UploadedFile $file):array
    {
        $path=$file->getRealPath();$size=$file->getSize();
        if(!$file->isValid()||!$path||!is_file($path)||!is_int($size)||$size<1||$size>5242880)
            throw new InvalidArgumentException('Signed bank or witnessed cash evidence up to 5 MB required.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($mime,['application/pdf','image/jpeg','image/png'],true))
            throw new InvalidArgumentException('Only private PDF/JPEG/PNG payment proof is allowed.');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Cannot retain accurate private payment evidence.');
        return [$bytes,$size,(string)$mime,hash('sha256',$bytes)];
    }

    private function intact(object $row):void
    {
        $storage=Storage::disk('local');
        if(!$storage->exists($row->evidence_path))
            throw new ConflictHttpException('Private payment proof missing.');
        $bytes=$storage->get($row->evidence_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
            !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Payment evidence hash mismatch; no money can be posted.');
    }

    private function agreement(string $companyId,string $id):object
    {
        $row=DB::table('hall_inventory_financial_obligations as o')
            ->join('hall_inventory_financial_obligation_decisions as d',function($q):void{
                $q->on('d.company_id','=','o.company_id')->on('d.obligation_id','=','o.id');
            })
            ->where('o.company_id',$companyId)->where('o.id',$id)
            ->where('d.decision','authorized')
            ->first(['o.id','o.company_id','o.claim_id','o.loan_id','o.source_hall_id',
                'o.currency','o.amount_minor','o.proposed_by','d.decided_by']);
        if(!$row)throw new ConflictHttpException('No independently authorized collectible obligation exists.');
        return $row;
    }

    public function record(User $actor,string $companyId,string $obligationId,
        string $receiptId,string $method,int $amountMinor,?string $reference,
        UploadedFile $file):string
    {
        $this->gate(false);$this->ids($companyId,$obligationId,$receiptId);
        if(!in_array($method,['cash','bank'],true)||$amountMinor<1||
            $amountMinor>100000000000||
            ($method==='cash' && $reference!==null)||
            ($method==='bank' && ($reference===null||
                !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{3,119}\z/D',$reference))))
            throw new InvalidArgumentException('Valid single-use bank reference or witnessed cash required.');
        [$bytes,$size,$mime,$sha]=$this->evidence($file);
        $fingerprint=hash('sha256',json_encode([
            $companyId,$obligationId,$method,$amountMinor,$reference,$sha,(int)$actor->getKey()
        ],JSON_THROW_ON_ERROR));
        $disk=Storage::disk('local');
        return DB::transaction(function()use($actor,$companyId,$obligationId,$receiptId,
            $method,$amountMinor,$reference,$bytes,$size,$mime,$sha,$fingerprint,$disk):string {
            abort_unless(DB::table('companies')->where('id',$companyId)
                ->where('is_active',true)->lockForUpdate()->exists(),403);
            $agreement=$this->agreement($companyId,$obligationId);
            if(DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$companyId)
                ->where('loan_id',$agreement->loan_id)->exists())
                throw new ConflictHttpException('Final NCL certificate locks the recorded financial recovery state.');

            $this->authorize($actor,$companyId,(string)$agreement->source_hall_id,false);
            $old=DB::table('hall_inventory_financial_recovery_receipts')
                ->where('company_id',$companyId)->where('id',$receiptId)->first();
            if($old) {
                if($old->obligation_id!==$obligationId||
                    (int)$old->recorded_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Conflicting payment evidence replay.');
                $this->intact($old);
                return $receiptId;
            }
            if($method==='bank'&&(
                DB::table('hall_inventory_financial_recovery_receipts')
                    ->where('company_id',$companyId)->where('method','bank')
                    ->where('external_reference',$reference)->exists() ||
                DB::table('venue_invoice_payments')->where('company_id',$companyId)
                    ->where('method','bank')->where('external_reference',$reference)->exists()))
                throw new ConflictHttpException('This bank payment reference is already used in company financial evidence.');
            // Reserve pending unverified and confirmed-unreversed payment claims
            // under a single company lock. Neither rejected nor reversed is counted.
            $rows=DB::table('hall_inventory_financial_recovery_receipts as r')
                ->leftJoin('hall_inventory_financial_recovery_reviews as v',function($q):void {
                    $q->on('v.company_id','=','r.company_id')->on('v.receipt_id','=','r.id');
                })
                ->leftJoin('hall_inventory_financial_recovery_reversals as x',function($q):void {
                    $q->on('x.company_id','=','r.company_id')->on('x.receipt_id','=','r.id');
                })
                ->where('r.company_id',$companyId)->where('r.obligation_id',$obligationId)
                ->where(function($q):void{
                    $q->whereNull('v.id')->orWhere(function($inner):void{
                        $inner->where('v.decision','confirmed')->whereNull('x.id');
                    });
                })->sum('r.amount_minor');
            if($rows>=(int)$agreement->amount_minor ||
                $amountMinor>(int)$agreement->amount_minor-(int)$rows)
                throw new ConflictHttpException('Unverified and verified payment evidence exceeds collectible authorized cap.');
            $key='private/inventory-loss-payment/'.$companyId.'/'.$obligationId.'/'.$receiptId;
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Private physical payment proof storage failed.');
            try {
                DB::table('hall_inventory_financial_recovery_receipts')->insert([
                    'id'=>$receiptId,'company_id'=>$companyId,'obligation_id'=>$obligationId,
                    'claim_id'=>$agreement->claim_id,'loan_id'=>$agreement->loan_id,
                    'source_hall_id'=>$agreement->source_hall_id,'currency'=>$agreement->currency,
                    'amount_minor'=>$amountMinor,'method'=>$method,'external_reference'=>$reference,
                    'evidence_sha256'=>$sha,'evidence_size'=>$size,
                    'evidence_mime'=>$mime,'evidence_path'=>$key,
                    'fingerprint'=>$fingerprint,'recorded_by'=>$actor->getKey()
                ]);
                $this->audit($actor,$companyId,'unverified_payment_documented',
                    $receiptId,(string)$agreement->loan_id,[
                        'amountMinor'=>$amountMinor,'currency'=>$agreement->currency,
                        'evidenceSha256'=>$sha,'verified'=>false,'journalPosted'=>false]);
            }catch(\Throwable $e){$disk->delete($key);throw $e;}
            return $receiptId;
        },3);
    }

    public function verify(User $actor,string $companyId,string $receiptId,
        string $reviewId,string $decision,string $method,string $reference,string $reason):string
    {
        $this->gate($decision==='confirmed');$this->ids($companyId,$receiptId,$reviewId);
        $reason=trim($reason);$reference=trim($reference);
        if(!in_array($decision,['confirmed','rejected'],true)||
            !in_array($method,['verified_bank_statement','witnessed_cash_count'],true)||
            mb_strlen($reason)<10||mb_strlen($reason)>500||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._\/#:-]{11,159}\z/D',$reference))
            throw new InvalidArgumentException('Independent payment evidence verification required.');
        $fingerprint=hash('sha256',json_encode([
            $companyId,$receiptId,$decision,$method,$reference,$reason,(int)$actor->getKey()
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$companyId,$receiptId,$reviewId,
            $decision,$method,$reference,$reason,$fingerprint):string {
            abort_unless(DB::table('companies')->where('id',$companyId)
                ->where('is_active',true)->lockForUpdate()->exists(),403);
            $receipt=DB::table('hall_inventory_financial_recovery_receipts')
                ->where('company_id',$companyId)->where('id',$receiptId)
                ->lockForUpdate()->first();
            if(!$receipt)throw new ConflictHttpException('Physical payment documentation not found.');
            if(DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$companyId)
                ->where('loan_id',$receipt->loan_id)->exists())
                throw new ConflictHttpException('Final NCL certificate locks the recorded financial recovery state.');

            $this->authorize($actor,$companyId,(string)$receipt->source_hall_id,true);
            if((int)$receipt->recorded_by===(int)$actor->getKey())
                throw new AuthorizationException('Payment reporter cannot verify their own payment.');
            $agreement=$this->agreement($companyId,(string)$receipt->obligation_id);
            $this->intact($receipt);
            $old=DB::table('hall_inventory_financial_recovery_reviews')
                ->where('company_id',$companyId)->where('receipt_id',$receiptId)->first();
            if($old){
                if($old->id!==$reviewId||(int)$old->reviewed_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Payment decision already recorded.');
                return $reviewId;
            }
            if(($receipt->method==='bank'&&$method!=='verified_bank_statement')||
                ($receipt->method==='cash'&&$method!=='witnessed_cash_count'))
                throw new ConflictHttpException('Verification method does not match actual payment method.');
            $journal=null;
            if($decision==='confirmed'){
                if($receipt->method==='cash' && DB::table('finance_cash_closeouts')
                    ->where('company_id',$companyId)
                    ->where('currency_code',$receipt->currency)
                    ->where('close_date',now('UTC')->toDateString())->exists())
                    throw new ConflictHttpException('Current finance cash drawer is already closed.');
                $journal=$reviewId;
                DB::table('finance_journals')->insert([
                    'id'=>$journal,'company_id'=>$companyId,'entry_type'=>'inventory_recovery',
                    'currency_code'=>$receipt->currency,'amount_minor'=>$receipt->amount_minor,
                    'reversal_of'=>null,'created_by'=>$actor->getKey(),
                    'reason'=>'Verified recovery from outside-owned loan '.$receipt->loan_id
                ]);
                foreach([
                    [$receipt->method==='cash'?'cash_on_hand':'bank_clearing','debit'],
                    ['inventory_loss_recovery_income','credit']
                ] as [$code,$side])
                    DB::table('finance_journal_lines')->insert([
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                        'journal_id'=>$journal,'account_code'=>$code,'side'=>$side,
                        'amount_minor'=>$receipt->amount_minor
                    ]);
                DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced IMMEDIATE');
                DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced DEFERRED');
            }
            DB::table('hall_inventory_financial_recovery_reviews')->insert([
                'id'=>$reviewId,'company_id'=>$companyId,'receipt_id'=>$receiptId,
                'obligation_id'=>$receipt->obligation_id,'decision'=>$decision,
                'verification_method'=>$method,'verification_reference'=>$reference,
                'reason'=>$reason,'journal_id'=>$journal,'fingerprint'=>$fingerprint,
                'reviewed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'payment_reviewed',$reviewId,
                (string)$receipt->loan_id,[
                    'receiptId'=>$receiptId,'decision'=>$decision,
                    'amountMinor'=>$receipt->amount_minor,'currency'=>$receipt->currency,
                    'journalId'=>$journal,'verifiedEvidenceOnly'=>true
                ]);
            return $reviewId;
        },3);
    }

    public function reverse(User $actor,string $companyId,string $receiptId,
        string $reversalId,string $reason):string
    {
        $this->gate(true);$this->ids($companyId,$receiptId,$reversalId);
        $reason=trim($reason);
        if(mb_strlen($reason)<15||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independently auditable correction reason is required.');
        return DB::transaction(function()use($actor,$companyId,$receiptId,$reversalId,$reason):string {
            abort_unless(DB::table('companies')->where('id',$companyId)
                ->where('is_active',true)->lockForUpdate()->exists(),403);
            $receipt=DB::table('hall_inventory_financial_recovery_receipts')
                ->where('company_id',$companyId)->where('id',$receiptId)
                ->lockForUpdate()->first();
            if(!$receipt)throw new ConflictHttpException('Recovery payment record does not exist.');
            if(DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$companyId)
                ->where('loan_id',$receipt->loan_id)->exists())
                throw new ConflictHttpException('Final NCL certificate locks the recorded financial recovery state.');

            $this->authorize($actor,$companyId,(string)$receipt->source_hall_id,true);
            $review=DB::table('hall_inventory_financial_recovery_reviews')
                ->where('company_id',$companyId)->where('receipt_id',$receiptId)
                ->where('decision','confirmed')->first();
            if(!$review)throw new ConflictHttpException('Only independently verified posted money can be corrected.');
            if((int)$review->reviewed_by===(int)$actor->getKey())
                throw new AuthorizationException('Payment approver may not independently reverse their own posting.');
            $existing=DB::table('hall_inventory_financial_recovery_reversals')
                ->where('company_id',$companyId)->where('receipt_id',$receiptId)->first();
            if($existing){
                if($existing->id!==$reversalId||
                    (int)$existing->reversed_by!==(int)$actor->getKey()||
                    $existing->reason!==$reason)
                    throw new ConflictHttpException('Recovery was already reversed.');
                return $reversalId;
            }
            $journal=DB::table('finance_journals')
                ->where('company_id',$companyId)->where('id',$review->journal_id)
                ->where('entry_type','inventory_recovery')->first();
            if(!$journal||(int)$journal->amount_minor!==(int)$receipt->amount_minor||
                $journal->currency_code!==$receipt->currency)
                throw new ConflictHttpException('Exact original recovery finance journal mismatch.');
            $this->intact($receipt);
            DB::table('finance_journals')->insert([
                'id'=>$reversalId,'company_id'=>$companyId,'entry_type'=>'reversal',
                'currency_code'=>$receipt->currency,'amount_minor'=>$receipt->amount_minor,
                'reversal_of'=>$review->journal_id,'created_by'=>$actor->getKey(),
                'reason'=>$reason
            ]);
            foreach([
                ['inventory_loss_recovery_income','debit'],
                [$receipt->method==='cash'?'cash_on_hand':'bank_clearing','credit']
            ] as [$code,$side])
                DB::table('finance_journal_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'journal_id'=>$reversalId,'account_code'=>$code,'side'=>$side,
                    'amount_minor'=>$receipt->amount_minor
                ]);
            DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced IMMEDIATE');
            DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced DEFERRED');
            DB::table('hall_inventory_financial_recovery_reversals')->insert([
                'id'=>$reversalId,'company_id'=>$companyId,'receipt_id'=>$receiptId,
                'original_journal_id'=>$review->journal_id,
                'reversal_journal_id'=>$reversalId,
                'reason'=>$reason,'reversed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'recovery_reversed',$reversalId,
                (string)$receipt->loan_id,[
                    'receiptId'=>$receiptId,'reversalOf'=>$review->journal_id,
                    'amountMinor'=>$receipt->amount_minor,'currency'=>$receipt->currency
                ]);
            return $reversalId;
        },3);
    }

    public function history(User $actor,string $companyId,string $hallId,string $loanId):array
    {
        $this->gate(false);$this->ids($companyId,$hallId,$loanId);
        $this->authorize($actor,$companyId,$hallId,false);
        if(!DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$companyId)->where('id',$loanId)
            ->where('lending_hall_id',$hallId)->where('direction','external_out')->exists())
            throw new AuthorizationException('Other company or hall recovery is private.');
        $rows=DB::table('hall_inventory_financial_recovery_receipts as p')
            ->leftJoin('hall_inventory_financial_recovery_reviews as r',function($q):void{
                $q->on('r.company_id','=','p.company_id')->on('r.receipt_id','=','p.id');
            })
            ->leftJoin('hall_inventory_financial_recovery_reversals as v',function($q):void{
                $q->on('v.company_id','=','p.company_id')->on('v.receipt_id','=','p.id');
            })
            ->where('p.company_id',$companyId)->where('p.source_hall_id',$hallId)
            ->where('p.loan_id',$loanId)->orderByDesc('p.created_at')
            ->orderByDesc('p.id')->limit(200)
            ->get(['p.id','p.obligation_id','p.amount_minor','p.currency','p.method',
                'p.recorded_by','r.decision','r.reviewed_by','r.journal_id',
                'v.id as reversal_id'])
            ->map(static fn($x):array=>[
                'id'=>(string)$x->id,'obligationId'=>(string)$x->obligation_id,
                'amountMinor'=>(int)$x->amount_minor,'currency'=>(string)$x->currency,
                'method'=>(string)$x->method,'recordedBy'=>(int)$x->recorded_by,
                'decision'=>$x->decision,'reviewedBy'=>$x->reviewed_by,
                'journalId'=>$x->journal_id,'reversalId'=>$x->reversal_id
            ])->all();
        return $rows;
    }


    public function download(User $actor,string $companyId,string $hallId,
        string $loanId,string $receiptId):\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->gate(false);$this->ids($companyId,$hallId,$loanId,$receiptId);
        $this->authorize($actor,$companyId,$hallId,false);
        $loan=DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$companyId)->where('id',$loanId)
            ->where('lending_hall_id',$hallId)->where('direction','external_out')->first();
        if(!$loan)throw new AuthorizationException('Private recovery requires exact source lending hall.');
        $receipt=DB::table('hall_inventory_financial_recovery_receipts')
            ->where('company_id',$companyId)->where('source_hall_id',$hallId)
            ->where('loan_id',$loanId)->where('id',$receiptId)->first();
        if(!$receipt)throw new AuthorizationException('Other hall or loan payment evidence is private.');
        $this->intact($receipt);
        $bytes=Storage::disk('local')->get($receipt->evidence_path);
        $extension=match($receipt->evidence_mime){
            'application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg',
            default=>throw new ConflictHttpException('Stored private recovery evidence type invalid.')
        };
        $this->audit($actor,$companyId,'payment_evidence_downloaded',
            $receiptId,$loanId,['evidenceSha256'=>$receipt->evidence_sha256,
                'bytesIntact'=>true,'paymentAutomaticallyVerified'=>false]);
        return response()->streamDownload(static function()use($bytes):void {
            echo $bytes;
        },'inventory-recovery-evidence-'.$receiptId.'.'.$extension,[
            'Content-Type'=>$receipt->evidence_mime,'Cache-Control'=>'private, no-store',
            'Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff'
        ]);
    }

    public function outstanding(User $actor,string $companyId,string $hallId,string $loanId):array
    {
        // Re-authorize original hall/loan through the bounded, private read.
        $this->history($actor,$companyId,$hallId,$loanId);
        // Financial balances MUST include all history, not only the latest
        // 200 visible evidence rows or the latest 100 obligation proposals.
        $totals=DB::table('hall_inventory_financial_recovery_receipts as p')
            ->leftJoin('hall_inventory_financial_recovery_reviews as r',function($q):void {
                $q->on('r.company_id','=','p.company_id')->on('r.receipt_id','=','p.id');
            })
            ->leftJoin('hall_inventory_financial_recovery_reversals as v',function($q):void {
                $q->on('v.company_id','=','p.company_id')->on('v.receipt_id','=','p.id');
            })
            ->where('p.company_id',$companyId)->where('p.source_hall_id',$hallId)
            ->where('p.loan_id',$loanId)->groupBy('p.obligation_id')
            ->select('p.obligation_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN r.id IS NULL THEN p.amount_minor ELSE 0 END),0) AS pending_minor')
            ->selectRaw("COALESCE(SUM(CASE WHEN r.decision = 'confirmed' AND v.id IS NULL THEN p.amount_minor ELSE 0 END),0) AS paid_minor")
            ->get()->keyBy('obligation_id');
        $agreements=DB::table('hall_inventory_financial_obligations as o')
            ->join('hall_inventory_financial_obligation_decisions as d',function($q):void {
                $q->on('d.company_id','=','o.company_id')->on('d.obligation_id','=','o.id');
            })
            ->where('o.company_id',$companyId)->where('o.source_hall_id',$hallId)
            ->where('o.loan_id',$loanId)->where('d.decision','authorized')
            ->get(['o.id','o.amount_minor','o.currency']);
        $waivers=config('inventory_controls.loan_financial_waivers_enabled')
            ?app(HallInventoryFinancialWaiverService::class)->totals($companyId,$hallId,$loanId)
            :[];
        $amounts=[];
        foreach($agreements as $agreement){
            $id=(string)$agreement->id;
            $paid=(int)($totals[$id]->paid_minor??0);
            $pending=(int)($totals[$id]->pending_minor??0);
            $amount=(int)$agreement->amount_minor;
            $waived=(int)($waivers[$id]['approved']??0);
            $waiverPending=(int)($waivers[$id]['pending']??0);
            if($paid+$waived>$amount)
                throw new ConflictHttpException('Approved financial forgiveness and cash recovery exceed original obligation.');
            $amounts[$id]=['amountMinor'=>$amount,
                'currency'=>(string)$agreement->currency,'paidMinor'=>$paid,
                'pendingMinor'=>$pending,'waivedMinor'=>$waived,
                'waiverPendingMinor'=>$waiverPending,
                'remainingMinor'=>max(0,$amount-$paid-$waived),
                'unreservedMinor'=>max(0,$amount-$paid-$pending-$waived-$waiverPending)];
        }
        return $amounts;
    }

    private function audit(User $actor,string $companyId,string $action,string $id,
        string $loanId,array $data):void
    {
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.'.$action,
            'entity_type'=>'hall_inventory_financial_recovery','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId,'source'=>'manual_payment_evidence'],JSON_THROW_ON_ERROR)
        ]);
    }
}
