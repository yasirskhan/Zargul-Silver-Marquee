<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * INTERNAL HTML fragment for the future private HAFZATECH Billing Officer
 * console. The protected HTTP adapter must authorize BOTH financial summary
 * and customer-evidence scopes before calling this renderer. Never route the
 * renderer directly. No scripts, external links, bank data, session secrets,
 * approval forms, payment action or license activation.
 */
final class CentralStaffBillingDashboardRenderer
{
    /** @param array<string,mixed> $invoice
     * @param array<int,array<string,mixed>> $pending
     */
    public static function render(array $invoice,array $pending): string
    {
        $v=static fn(mixed $x):string=>htmlspecialchars(
            is_scalar($x)?(string)$x:'', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        // Keep display fields narrowly allowlisted: no private storage
        // keys, receiving account numbers, staff/token details or raw JSON.
        $rows='';
        foreach(array_slice($pending,0,25) as $row) {
            $rows.='<tr><td>'.$v($row['invoice_number']??'').
                '</td><td>'.$v($row['claimed_reference']??'').
                '</td><td>Unverified customer claim</td></tr>';
        }
        if($rows==='') $rows='<tr><td colspan="3">No pending customer claims</td></tr>';
        $net=$v($invoice['net_minor']??'0');
        $gross=$v($invoice['verified_minor']??'0');
        $reversals=$v($invoice['reversed_minor']??'0');
        $total=$v($invoice['total_minor']??'0');
        $currency=$v($invoice['currency']??'');
        $coverage=($invoice['net_covered']??false)===true
            ? 'Numerically covered — not a license approval'
            : 'Not fully covered by independently verified net settlement';
        return '<section aria-label="Private HAFZATECH billing status">'.
            '<h2>HAFZATECH billing evidence</h2>'.
            '<p>Read-only. Customer receipts are not bank settlement proof.</p>'.
            '<dl><dt>Invoice</dt><dd>'.$v($invoice['invoice_number']??'').
            '</dd><dt>Currency</dt><dd>'.$currency.
            '</dd><dt>Invoice total (minor units)</dt><dd>'.$total.
            '</dd><dt>Independently verified (minor units)</dt><dd>'.$gross.
            '</dd><dt>Refunded or reversed (minor units)</dt><dd>'.$reversals.
            '</dd><dt>Net verified (minor units)</dt><dd>'.$net.
            '</dd><dt>Settlement status</dt><dd>'.$coverage.
            '</dd></dl><h3>Pending customer-submitted evidence</h3>'.
            '<table><thead><tr><th>Invoice</th><th>Claimed reference</th>'.
            '<th>Verification</th></tr></thead><tbody>'.$rows.
            '</tbody></table></section>';
    }
}
