<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/** Customer-scoped installation directory with authorized record actions. */
final class CentralStaffInstallationDirectory
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    public function render(string $bearer, string $customerId, string $csrf): string
    {
        if (!$this->authorization->permits($bearer,'license.read_status',$customerId)) {
            throw new RegistrationError('installation_directory_denied');
        }
        if (preg_match('/^[a-f0-9]{64}$/D',$csrf)!==1) {
            throw new RegistrationError('invalid_installation_directory_request',422);
        }
        $query=$this->db->prepare(
            'SELECT id,approved_domain,status,created_at FROM registered_installations
             WHERE customer_id=:customer ORDER BY created_at DESC');
        $query->execute(['customer'=>$customerId]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $proof='<input type="hidden" name="staff_csrf" value="'.$e($csrf).'">'
            .'<input type="hidden" name="customer_id" value="'.$e($customerId).'">';
        $body='';
        foreach($rows as $row) {
            $id=$e((string)$row['id']);
            $body.='<tr><td><code>'.$id.'</code></td><td>'.$e((string)$row['approved_domain'])
                .'</td><td>'.$e((string)$row['status']).'</td><td>'
                .'<form method="post" action="/staff/support/tickets">'.$proof
                .'<input type="hidden" name="installation_id" value="'.$id.'">'
                .'<button type="submit">Installation support</button></form></td></tr>';
        }
        if($body==='') {
            $body='<tr><td colspan="4">No registered installation for this customer.</td></tr>';
        }

        return '<section aria-label="Private HAFZATECH installation directory">'
            .'<h1>Installations</h1><p>Customer-scoped installations and authorized support actions.</p>'
            .'<table><thead><tr><th>Installation</th><th>Domain</th><th>Status</th><th>Action</th></tr></thead>'
            .'<tbody>'.$body.'</tbody></table>'
            .'<form method="post" action="/staff/customers/directory">'.$proof
            .'<input type="hidden" name="search" value="">'
            .'<button type="submit">Back to customers</button></form></section>';
    }
}