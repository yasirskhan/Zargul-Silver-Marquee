<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Private HAFZATECH License Officer queue. Rendering never signs, delivers,
 * approves payment or activates a license. Only an authorized follow-up
 * action may configure the signed designer-credit policy.
 */
final class CentralStaffLicenseQueueHtmlRenderer
{
    /** @param array<int,array<string,mixed>> $rows */
    public static function render(array $rows, ?string $csrf = null): string
    {
        $escape=static fn(mixed $value):string=>htmlspecialchars(
            is_scalar($value)?(string)$value:'',
            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $proof=is_string($csrf) && preg_match('/^[a-f0-9]{64}$/D',$csrf)===1
            ? '<input type="hidden" name="staff_csrf" value="'.$escape($csrf).'">' : '';
        $items='';
        foreach (array_slice($rows,0,25) as $row) {
            $license=$escape($row['license_id']??'');
            $items.='<tr><td>'.$escape($row['invoice_number']??'').
                '</td><td>'.$license.
                '</td><td>'.$escape($row['revision']??'').
                '</td><td>'.$escape($row['approved_domain']??'').
                '</td><td>'.$escape($row['expires_at']??'').
                '</td><td>Awaiting isolated signer</td><td>';
            if($proof!=='' && $license!=='') {
                $items.='<form method="post" action="/staff/licenses/designer-credit">'.$proof
                    .'<input type="hidden" name="license_id" value="'.$license.'">'
                    .'<button type="submit">Designer credit</button></form>';
            } else {
                $items.='—';
            }
            $items.='</td></tr>';
        }
        if($items==='') {
            $items='<tr><td colspan="7">No pending signed-revision requests</td></tr>';
        }

        return '<section aria-label="Private HAFZATECH licensing queue">'
            .'<h2>Pending license revisions</h2>'
            .'<p>Read-only queue. A pending revision is not signed, delivered, paid or activated. '
            .'Designer credit configuration is a separate authorized action.</p>'
            .'<table><thead><tr><th>Invoice</th><th>License</th><th>Revision</th>'
            .'<th>Approved domain</th><th>Expiry</th><th>Signer status</th><th>Action</th>'
            .'</tr></thead><tbody>'.$items.'</tbody></table></section>';
    }
}