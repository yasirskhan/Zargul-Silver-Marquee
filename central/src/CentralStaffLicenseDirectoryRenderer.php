<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/** Escaped customer license directory. Read-only; no signing or payment. */
final class CentralStaffLicenseDirectoryRenderer
{
    public static function html(array $rows,string $customerId): string
    {
        $e=static fn(mixed $v):string=>htmlspecialchars(
            is_scalar($v)?(string)$v:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $body='';
        foreach($rows as $row){
            $expiry=is_string($row['expires_at']??null)&&$row['expires_at']!==''
                ? $e($row['expires_at']) : 'Not yet signed';
            $body.='<tr><td>'.$e($row['approved_domain']??'').'</td><td>'
                .$e($row['installation_status']??'').'</td><td>'
                .$e($row['current_revision']??'').'</td><td>'.$e($row['term_months']??'').'</td><td>'
                .$expiry.'</td><td>'.$e($row['revision_count']??'').'</td><td>'
                .$e($row['pending_count']??'').'</td></tr>';
        }
        if($body==='')$body='<tr><td colspan="7">No licenses recorded for this customer.</td></tr>';
        return '<section aria-label="Private HAFZATECH customer licenses">'
            .'<h2>Customer licenses and renewals</h2>'
            .'<p>Read-only revision and expiry directory. Renewal preparation requires '
            .'an independently paid invoice and isolated signing.</p>'
            .'<table><thead><tr><th>Approved domain</th><th>Installation</th>'
            .'<th>Current revision</th><th>Term</th><th>Expires</th>'
            .'<th>Revisions</th><th>Awaiting signer</th></tr></thead><tbody>'
            .$body.'</tbody></table>'
            .'<p><a href="/staff/customers/'.$e($customerId).'/billing">Customer billing</a> · '
            .'<a href="/staff/customers/'.$e($customerId).'/support">Support tickets</a></p></section>';
    }
}