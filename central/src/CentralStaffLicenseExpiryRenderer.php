<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/** Escaped effective-license expiry watch; no signing or payment material. */
final class CentralStaffLicenseExpiryRenderer
{
    /** @param array<int,array<string,mixed>> $rows */
    public static function html(array $rows): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '';
        foreach ($rows as $row) {
            $id = (string) ($row['customer_id'] ?? '');
            if (preg_match('/^[a-f0-9-]{36}$/D', $id) !== 1) continue;
            $expires = (string) ($row['expires_at'] ?? '');
            $expired = strtotime($expires) !== false && strtotime($expires) < time();
            $body .= '<tr><td>'.$e((string) ($row['legal_name'] ?? '')).'</td>'
                .'<td>'.$e((string) ($row['approved_domain'] ?? '')).'</td>'
                .'<td>'.$e($expires).'</td>'
                .'<td><span class="metric-card">'.($expired ? 'Expired' : 'Expiring').'</span></td>'
                .'<td><a href="/staff/customers/'.$e($id).'/licensing">Open licensing</a></td></tr>';
        }
        if ($body === '') $body = '<tr><td colspan="5">No effective signed license expires within 30 days.</td></tr>';
        return '<section aria-label="License expiry watch"><h1>License expiry watch</h1>'
            .'<p>Current signed revisions only. Historical superseded revisions are excluded.</p>'
            .'<table><thead><tr><th>Customer</th><th>Domain</th><th>Expires</th><th>State</th><th>Action</th></tr></thead>'
            .'<tbody>'.$body.'</tbody></table></section>';
    }
}
