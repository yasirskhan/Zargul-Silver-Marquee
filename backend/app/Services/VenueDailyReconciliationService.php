<?php

namespace App\Services;

use App\Models\User;
use App\Policies\FinanceAccessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, company-scoped daily deposit/refund reconciliation.
 * Source of truth remains the existing immutable customer receipt and journal rows.
 */
final class VenueDailyReconciliationService
{
    public function forDate(User $actor, string $companyId, string $utcDate): array
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! app(FinanceAccessPolicy::class)->view($actor, $companyId)) {
            throw new AuthorizationException('Only current company owner/admin may reconcile.');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $utcDate, 'UTC');
        if ($date === false || $date->format('Y-m-d') !== $utcDate) {
            throw new InvalidArgumentException('Valid UTC reconciliation date required.');
        }
        $start = $date->startOfDay()->toDateTimeString();
        $end = $date->addDay()->startOfDay()->toDateTimeString();
        $payments = DB::table('venue_invoice_payments')->where('company_id', $companyId)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->get(['id', 'journal_id', 'invoice_id', 'receipt_number', 'currency_code',
                'method', 'amount_minor']);
        $refunds = DB::table('venue_invoice_refunds as r')
            ->join('venue_invoice_payments as p', function ($join): void {
                $join->on('p.id', '=', 'r.payment_id')
                    ->on('p.company_id', '=', 'r.company_id');
            })->where('r.company_id', $companyId)
            ->where('r.created_at', '>=', $start)->where('r.created_at', '<', $end)
            ->get(['r.id', 'r.journal_id', 'r.invoice_id', 'r.refund_number',
                'r.currency_code', 'r.amount_minor', 'p.method']);
        $buckets = [];
        $bucket = static function (string $currency) use (&$buckets): void {
            if (! isset($buckets[$currency])) {
                $buckets[$currency] = [
                    'currency' => $currency, 'cashReceivedMinor' => 0,
                    'bankReceivedMinor' => 0, 'cashRefundedMinor' => 0,
                    'bankRefundedMinor' => 0, 'netCashMinor' => 0,
                    'netBankMinor' => 0, 'receiptCount' => 0, 'refundCount' => 0,
                    'ledgerMatched' => true,
                ];
            }
        };
        $linesMatch = static function (string $id, int $amount, string $method,
            bool $refund, string $company): bool {
            $account = $method === 'cash' ? 'cash_on_hand' : 'bank_clearing';
            $actual = DB::table('finance_journal_lines')
                ->where('company_id', $company)->where('journal_id', $id)
                ->get(['account_code', 'side', 'amount_minor'])
                ->map(static fn ($line): array => [
                    $line->account_code, $line->side, (int) $line->amount_minor,
                ])->all();
            $expected = $refund
                ? [[$account, 'credit', $amount],
                    ['customer_deposit_liability', 'debit', $amount]]
                : [[$account, 'debit', $amount],
                    ['customer_deposit_liability', 'credit', $amount]];
            sort($actual);
            sort($expected);
            return $actual === $expected;
        };
        // Compare immutable finance_journals against each recorded receipt/refund,
        // not merely totals, so missing or wrongly typed journal lines fail visibly.
        foreach ($payments as $row) {
            $bucket($row->currency_code);
            $key = $row->method === 'cash' ? 'cashReceivedMinor' : 'bankReceivedMinor';
            $buckets[$row->currency_code][$key] += (int) $row->amount_minor;
            $buckets[$row->currency_code]['receiptCount']++;
            $journal = DB::table('finance_journals')->where('company_id', $companyId)
                ->where('id', $row->journal_id)
                ->where('entry_type', 'customer_deposit')
                ->where('currency_code', $row->currency_code)
                ->where('amount_minor', $row->amount_minor)->exists();
            $buckets[$row->currency_code]['ledgerMatched'] = $journal
                && $linesMatch($row->journal_id, (int) $row->amount_minor,
                    $row->method, false, $companyId)
                && $buckets[$row->currency_code]['ledgerMatched'];
        }
        foreach ($refunds as $row) {
            $bucket($row->currency_code);
            $key = $row->method === 'cash' ? 'cashRefundedMinor' : 'bankRefundedMinor';
            $buckets[$row->currency_code][$key] += (int) $row->amount_minor;
            $buckets[$row->currency_code]['refundCount']++;
            $journal = DB::table('finance_journals')->where('company_id', $companyId)
                ->where('id', $row->journal_id)
                ->where('entry_type', 'reversal')
                ->where('currency_code', $row->currency_code)
                ->where('amount_minor', $row->amount_minor)->exists();
            $buckets[$row->currency_code]['ledgerMatched'] = $journal
                && $linesMatch($row->journal_id, (int) $row->amount_minor,
                    $row->method, true, $companyId)
                && $buckets[$row->currency_code]['ledgerMatched'];
        }
        foreach ($buckets as &$row) {
            $row['netCashMinor'] = $row['cashReceivedMinor'] - $row['cashRefundedMinor'];
            $row['netBankMinor'] = $row['bankReceivedMinor'] - $row['bankRefundedMinor'];
        }
        unset($row);
        ksort($buckets);
        return ['utcDate' => $utcDate, 'currencies' => array_values($buckets),
            'receiptCount' => count($payments), 'refundCount' => count($refunds),
            'cashCloseouts' => DB::table('finance_cash_closeouts')
                ->where('company_id', $companyId)->where('close_date', $utcDate)
                ->orderBy('currency_code')
                ->get(['id', 'currency_code', 'expected_minor', 'counted_minor', 'variance_minor'])
                ->map(static fn ($row): array => [
                    'id' => $row->id, 'currency' => $row->currency_code,
                    'expectedMinor' => (int) $row->expected_minor,
                    'countedMinor' => (int) $row->counted_minor,
                    'varianceMinor' => (int) $row->variance_minor,
                ])->all(),
            'readOnly' => true];
    }
}
