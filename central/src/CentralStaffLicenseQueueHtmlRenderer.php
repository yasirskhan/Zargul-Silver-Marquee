<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Read-only private HAFZATECH License Officer queue fragment.
 * Input is ONLY the bounded, live MFA/customer-scoped signing queue result.
 * Rendering a pending request NEVER approves a payment, accesses a private
 * signing key or authorizes a license revision to be signed.
 */
final class CentralStaffLicenseQueueHtmlRenderer
{
    /** @param array<int,array<string,mixed>> $rows */
    public static function render(array $rows): string
    {
        $escape=static fn(mixed $value):string=>htmlspecialchars(
            is_scalar($value)?(string)$value:'',
            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $items='';
        foreach (array_slice($rows,0,25) as $row) {
            $items.='<tr><td>'.$escape($row['invoice_number']??'').
                '</td><td>'.$escape($row['license_id']??'').
                '</td><td>'.$escape($row['revision']??'').
                '</td><td>'.$escape($row['approved_domain']??'').
                '</td><td>'.$escape($row['expires_at']??'').
                '</td><td>Awaiting isolated signer</td></tr>';
        }
        if ($items==='') {
            $items='<tr><td colspan="6">No pending signed-revision requests</td></tr>';
        }
        return '<section aria-label="Private HAFZATECH licensing queue">'.
            '<h2>Pending license revisions</h2>'.
            '<p>Read-only. A pending revision is not signed, delivered, '.
            'paid or activated merely by appearing here.</p>'.
            '<table><thead><tr><th>Invoice</th><th>License</th>'.
            '<th>Revision</th><th>Approved domain</th><th>Expiry</th>'.
            '<th>Signer status</th></tr></thead><tbody>'.$items.
            '</tbody></table></section>';
    }
}
