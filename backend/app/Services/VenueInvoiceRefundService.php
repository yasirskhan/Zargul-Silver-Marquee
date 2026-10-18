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

/** Owner-controlled full receipt correction from a previously approved refund. */
final class VenueInvoiceRefundService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate(): void
    {
        if (config('refund_controls.enabled') !== true
            || config('payment_controls.enabled') !== true
            || config('finance_controls.posting_enabled') !== true) {
            throw new ConflictHttpException('Refund correction needs three explicit operator release gates.');
        }
    }

    public function record(User $actor, string $companyId, string $paymentId,
        string $refundId, string $approvalId, string $reason): array
    {
        $this->gate();
        if (! Str::isUuid($paymentId) || ! Str::isUuid($refundId) || ! Str::isUuid($approvalId)) {
            throw new InvalidArgumentException('Exact payment, refund and approval references required.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $reason)) {
            throw new InvalidArgumentException('Safe audited refund reason required.');
        }
        return DB::transaction(function () use ($actor, $companyId, $paymentId,
            $refundId, $approvalId, $reason): array {
            $this->gate();
            abort_unless(DB::table('companies')->where('id', $companyId)
                ->where('is_active', true)->lockForUpdate()->exists(), 403);
            $payment = DB::table('venue_invoice_payments')
                ->where('company_id', $companyId)->where('id', $paymentId)->first();
            if (! $payment) {
                throw new AuthorizationException('Receipt is not available.');
            }
            $invoice = DB::table('venue_invoices')
                ->where('company_id', $companyId)->where('id', $payment->invoice_id)->first();
            $reservation = $invoice ? DB::table('venue_reservations')
                ->where('company_id', $companyId)->where('id', $invoice->reservation_id)->first() : null;
            $hall = $reservation ? Hall::query()->where('company_id', $companyId)
                ->where('id', $reservation->hall_id)->where('is_active', true)->first() : null;
            if (! $invoice || ! $reservation || ! $hall || ! Auth::check()
                || (int) Auth::id() !== (int) $actor->getKey()
                || ! $this->access->canCorrectExistingApprovedRefund($actor, $hall)) {
                throw new AuthorizationException('Current owner/admin refund authority required.');
            }
            $approval = DB::table('approval_requests')
                ->where('company_id', $companyId)->where('id', $approvalId)->first();
            $decision = DB::table('approval_decisions')
                ->where('company_id', $companyId)->where('approval_id', $approvalId)
                ->where('decision', 'approved')->first();
            if (! $approval || ! $decision || $approval->status !== 'approved'
                || $approval->category !== 'refund'
                || $approval->booking_id !== $reservation->source_draft_id
                || $approval->currency !== $invoice->currency_code
                || (int) $approval->amount_minor !== (int) $payment->amount_minor
                || (int) $decision->decided_by === (int) $approval->requested_by) {
                throw new ConflictHttpException('A separate approved full-payment refund is required.');
            }
            $prior = DB::table('venue_invoice_refunds')->where('id', $refundId)->first();
            $byPayment = DB::table('venue_invoice_refunds')
                ->where('company_id', $companyId)->where('payment_id', $paymentId)->first();
            $byApproval = DB::table('venue_invoice_refunds')
                ->where('company_id', $companyId)->where('approval_id', $approvalId)->first();
            if ($prior || $byPayment || $byApproval) {
                if ($prior && $byPayment && $byApproval
                    && $prior->id === $byPayment->id && $prior->id === $byApproval->id
                    && $prior->company_id === $companyId
                    && $prior->reason === $reason
                    && (int) $prior->recorded_by === (int) $actor->getKey()) {
                    return $this->summary($prior);
                }
                throw new ConflictHttpException('Refund request or original payment is already reversed.');
            }
            if ($payment->method === 'cash'
                && DB::table('finance_cash_closeouts')->where('company_id', $companyId)
                    ->where('currency_code', $payment->currency_code)
                    ->where('close_date', now('UTC')->toDateString())->exists()) {
                throw new ConflictHttpException('Cash drawer is closed for this refund date.');
            }
            $number = 1 + (int) DB::table('venue_invoice_refunds')
                ->where('company_id', $companyId)->max('number');
            if ($number > 999999999) {
                throw new ConflictHttpException('Refund document numbers exhausted.');
            }
            $reference = 'RF-'.str_pad((string) $number, 9, '0', STR_PAD_LEFT);
            DB::table('finance_journals')->insert([
                'id' => $refundId, 'company_id' => $companyId,
                'entry_type' => 'reversal', 'currency_code' => $payment->currency_code,
                'amount_minor' => $payment->amount_minor, 'reversal_of' => $payment->journal_id,
                'created_by' => $actor->getKey(), 'reason' => $reason,
            ]);
            foreach ([
                ['customer_deposit_liability', 'debit'],
                [$payment->method === 'cash' ? 'cash_on_hand' : 'bank_clearing', 'credit'],
            ] as [$account, $side]) {
                DB::table('finance_journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId,
                    'journal_id' => $refundId, 'account_code' => $account,
                    'side' => $side, 'amount_minor' => $payment->amount_minor,
                ]);
            }
            DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced IMMEDIATE');
            DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced DEFERRED');
            DB::table('venue_invoice_refunds')->insert([
                'id' => $refundId, 'company_id' => $companyId,
                'invoice_id' => $invoice->id, 'payment_id' => $paymentId,
                'approval_id' => $approvalId, 'journal_id' => $refundId,
                'number' => $number, 'refund_number' => $reference,
                'currency_code' => $invoice->currency_code,
                'amount_minor' => $payment->amount_minor,
                'reason' => $reason, 'recorded_by' => $actor->getKey(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'venue_refund.recorded',
                'entity_type' => 'venue_invoice_refund', 'entity_id' => $refundId,
                'before_values' => null,
                'after_values' => json_encode([
                    'invoiceId' => $invoice->id, 'paymentId' => $paymentId,
                    'approvalId' => $approvalId, 'refundNumber' => $reference,
                    'amountMinor' => (int) $payment->amount_minor,
                    'currency' => $payment->currency_code,
                    'journalId' => $refundId,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
            return $this->summary(DB::table('venue_invoice_refunds')->where('id', $refundId)->first());
        }, 3);
    }

    private function summary(object $refund): array
    {
        return ['id' => $refund->id, 'invoiceId' => $refund->invoice_id,
            'paymentId' => $refund->payment_id, 'approvalId' => $refund->approval_id,
            'refundNumber' => $refund->refund_number,
            'currency' => $refund->currency_code,
            'amountMinor' => (int) $refund->amount_minor,
            'journalId' => $refund->journal_id];
    }
}
