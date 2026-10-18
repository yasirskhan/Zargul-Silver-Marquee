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

/** Controlled invoice deposit subledger backed by existing balanced cash ledger. */
final class VenueInvoicePaymentService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function authorize(User $actor, string $companyId, string $hallId): void
    {
        $hall = Hall::query()->where('company_id', $companyId)
            ->where('id', $hallId)->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $this->access->canManageCustomerProfiles($actor, $companyId)
            || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('Current owner/admin customer payment authority required.');
        }
    }

    private function summary(object $p): array
    {
        return ['id' => $p->id, 'invoiceId' => $p->invoice_id,
            'receiptNumber' => $p->receipt_number, 'amountMinor' => (int) $p->amount_minor,
            'currency' => $p->currency_code, 'method' => $p->method,
            'reference' => $p->external_reference, 'journalId' => $p->journal_id];
    }

    public function history(User $actor, string $companyId, string $invoiceId): array
    {
        if (! Str::isUuid($invoiceId)) {
            throw new InvalidArgumentException('Invalid invoice reference.');
        }
        $invoice = DB::table('venue_invoices as i')
            ->join('venue_reservations as r', fn ($join) => $join
                ->on('r.id', '=', 'i.reservation_id')->on('r.company_id', '=', 'i.company_id'))
            ->where('i.company_id', $companyId)->where('i.id', $invoiceId)
            ->first(['i.id','i.total_minor','i.currency_code','r.hall_id']);
        if (! $invoice) {
            throw new AuthorizationException('Invoice is not accessible.');
        }
        // Historical invoice statements survive a signed subscription's
        // business-operations cutoff. This separate permission is read-only
        // and still requires a LIVE owner/admin account, bound company and
        // exact existing hall/invoice. New payment recording below continues
        // to use the unrestricted business authorization gate.
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$invoice->hall_id)->where('is_active',true)->first();
        if (!$hall || ! Auth::check() || (int)Auth::id()!==(int)$actor->getKey()
            || ! $this->access->canReadExistingInvoiceHistory($actor,$hall)) {
            throw new AuthorizationException('Historic invoice is not accessible to this company owner.');
        }
        $payments = DB::table('venue_invoice_payments')->where('company_id', $companyId)
            ->where('invoice_id', $invoiceId)->orderBy('number')->limit(200)
            ->get()->map(fn ($p) => $this->summary($p))->all();
        $gross = (int) DB::table('venue_invoice_payments')->where('company_id', $companyId)
            ->where('invoice_id', $invoiceId)->sum('amount_minor');
        $refunded = (int) DB::table('venue_invoice_refunds')->where('company_id', $companyId)
            ->where('invoice_id', $invoiceId)->sum('amount_minor');
        $refunds = DB::table('venue_invoice_refunds')->where('company_id', $companyId)
            ->where('invoice_id', $invoiceId)->orderBy('number')->limit(200)
            ->get(['id', 'refund_number', 'payment_id', 'approval_id', 'amount_minor'])
            ->map(fn ($row) => [
                'id' => $row->id, 'refundNumber' => $row->refund_number,
                'paymentId' => $row->payment_id, 'approvalId' => $row->approval_id,
                'amountMinor' => (int) $row->amount_minor,
            ])->all();
        $net = $gross - $refunded;
        return ['invoiceId' => $invoiceId, 'currency' => $invoice->currency_code,
            'totalMinor' => (int) $invoice->total_minor, 'paidMinor' => $net,
            'refundedMinor' => $refunded, 'dueMinor' => (int) $invoice->total_minor - $net,
            'payments' => $payments, 'refunds' => $refunds];
    }

    public function record(
        User $actor, string $companyId, string $invoiceId, string $paymentId,
        string $method, int $amountMinor, ?string $externalReference, string $reason
    ): array {
        if (config('payment_controls.enabled') !== true ||
            config('finance_controls.posting_enabled') !== true) {
            throw new ConflictHttpException('Customer deposits require separate operator finance release.');
        }
        if (! Str::isUuid($invoiceId) || ! Str::isUuid($paymentId)
            || ! in_array($method, ['cash','bank'], true) || $amountMinor < 1
            || $amountMinor > 100000000000) {
            throw new InvalidArgumentException('Invalid invoice, payment ID, method or deposit amount.');
        }
        $reason = trim($reason);
        $ref = $externalReference === null ? null : trim($externalReference);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>@[:cntrl:]]/u', $reason)
            || ($method === 'cash' && $ref !== null)
            || ($method === 'bank' && ($ref === null || mb_strlen($ref) < 4
                || mb_strlen($ref) > 120 || ! preg_match('/^[A-Za-z0-9._-]+$/D', $ref)))) {
            throw new InvalidArgumentException('An auditable reason and safe method reference are required.');
        }

        return DB::transaction(function () use ($actor, $companyId, $invoiceId, $paymentId,
            $method, $amountMinor, $ref, $reason): array {
            if (config('payment_controls.enabled') !== true
                || config('finance_controls.posting_enabled') !== true) {
                throw new ConflictHttpException('Customer payment release is closed.');
            }
            // Lock the same company row used by quote/invoice issuance: serial
            // sequence allocation, simultaneous invoice deposits and cash close.
            abort_unless(DB::table('companies')->where('id', $companyId)
                ->where('is_active', true)->lockForUpdate()->exists(), 403);
            $invoice = DB::table('venue_invoices')->where('company_id', $companyId)
                ->where('id', $invoiceId)->first();
            if (! $invoice) {
                throw new AuthorizationException('Invoice is not available in this company.');
            }
            $reservation = DB::table('venue_reservations')->where('company_id', $companyId)
                ->where('id', $invoice->reservation_id)->first();
            if (! $reservation) {
                throw new AuthorizationException('Linked reservation is unavailable.');
            }
            $this->authorize($actor, $companyId, $reservation->hall_id);
            $existing = DB::table('venue_invoice_payments')->where('id', $paymentId)->first();
            if ($existing) {
                if ($existing->company_id === $companyId && $existing->invoice_id === $invoiceId
                    && (int) $existing->amount_minor === $amountMinor
                    && $existing->method === $method && $existing->external_reference === $ref
                    && $existing->reason === $reason && (int) $existing->created_by === (int) $actor->getKey()) {
                    return $this->summary($existing);
                }
                throw new ConflictHttpException('Payment idempotency key was reused with different details.');
            }
            $paid = (int) DB::table('venue_invoice_payments')->where('company_id', $companyId)
                ->where('invoice_id', $invoiceId)->sum('amount_minor');
            $refunded = (int) DB::table('venue_invoice_refunds')->where('company_id', $companyId)
                ->where('invoice_id', $invoiceId)->sum('amount_minor');
            if ($amountMinor > (int) $invoice->total_minor - ($paid - $refunded)) {
                throw new ConflictHttpException('Deposit exceeds the invoice balance.');
            }
            if ($method === 'cash' && DB::table('finance_cash_closeouts')
                ->where('company_id', $companyId)->where('currency_code', $invoice->currency_code)
                ->where('close_date', now('UTC')->toDateString())->exists()) {
                throw new ConflictHttpException('Cash drawer for today is closed.');
            }
            $number = 1 + (int) DB::table('venue_invoice_payments')
                ->where('company_id', $companyId)->max('number');
            if ($number > 999999999) {
                throw new ConflictHttpException('Receipt sequence exhausted.');
            }
            $receipt = 'RCT-'.str_pad((string) $number, 9, '0', STR_PAD_LEFT);
            DB::table('finance_journals')->insert([
                'id' => $paymentId, 'company_id' => $companyId, 'entry_type' => 'customer_deposit',
                'currency_code' => $invoice->currency_code, 'amount_minor' => $amountMinor,
                'reversal_of' => null, 'created_by' => $actor->getKey(), 'reason' => $reason,
            ]);
            foreach ([
                [$method === 'cash' ? 'cash_on_hand' : 'bank_clearing', 'debit'],
                ['customer_deposit_liability','credit'],
            ] as [$account, $side]) {
                DB::table('finance_journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId,
                    'journal_id' => $paymentId, 'account_code' => $account,
                    'side' => $side, 'amount_minor' => $amountMinor,
                ]);
            }
            DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced IMMEDIATE');
            DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced DEFERRED');
            DB::table('venue_invoice_payments')->insert([
                'id' => $paymentId, 'company_id' => $companyId, 'invoice_id' => $invoiceId,
                'journal_id' => $paymentId, 'number' => $number, 'receipt_number' => $receipt,
                'method' => $method, 'external_reference' => $ref,
                'currency_code' => $invoice->currency_code, 'amount_minor' => $amountMinor,
                'reason' => $reason, 'created_by' => $actor->getKey(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'venue_payment.deposit_recorded',
                'entity_type' => 'venue_invoice_payment', 'entity_id' => $paymentId,
                'before_values' => null,
                'after_values' => json_encode(['invoiceId'=>$invoiceId,'receipt'=>$receipt,
                    'amountMinor'=>$amountMinor,'currency'=>$invoice->currency_code,
                    'method'=>$method,'journalId'=>$paymentId], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason'=>$reason], JSON_THROW_ON_ERROR),
            ]);
            return $this->summary(DB::table('venue_invoice_payments')->where('id', $paymentId)->first());
        }, 3);
    }
}
