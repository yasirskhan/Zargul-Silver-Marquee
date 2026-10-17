<?php
declare(strict_types=1);

use Hafzatech\Central\StaffRolePermissions;

require dirname(__DIR__).'/src/StaffRolePermissions.php';

function verify(bool $assertion, string $label): void {
    if (! $assertion) throw new RuntimeException('FAIL central staff permissions: '.$label);
}
$dsn = getenv('CENTRAL_DB_DSN');
if (! $dsn || ! str_contains($dsn, 'hafzatech_central_test')) {
    throw new RuntimeException('Do not run staff tests outside isolated test DB.');
}
$db = new PDO($dsn, getenv('CENTRAL_DB_USERNAME'), getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rows = $db->query('SELECT role, capability FROM central_role_capabilities ORDER BY role, capability')
    ->fetchAll(PDO::FETCH_ASSOC);
$actual = [];
foreach ($rows as $r) $actual[$r['role']][] = $r['capability'];
foreach (StaffRolePermissions::ROLE_PERMISSIONS as $role => $capabilities) {
    sort($capabilities);
    $fromDb = $actual[$role] ?? [];
    sort($fromDb);
    verify($fromDb === $capabilities, 'DB role template parity '.$role);
}
verify(count($actual) === 9, 'exactly nine central staff templates');
verify(StaffRolePermissions::roleAllows('billing_officer','billing.payment.verify'), 'billing verifies payment');
verify(!StaffRolePermissions::roleAllows('billing_officer','license.issue_paid'), 'billing cannot issue paid license');
verify(!StaffRolePermissions::roleAllows('license_officer','billing.payment.verify'), 'issuer cannot verify payment');
verify(!StaffRolePermissions::roleAllows('support_agent','billing.invoice.issue'), 'support is read/reply only');
verify(!StaffRolePermissions::roleAllows('super_administrator','billing.payment.verify'), 'super admin not automatic cashier');
verify(!StaffRolePermissions::roleAllows('auditor','license.revoke.approve'), 'auditor read-only');
verify(!StaffRolePermissions::compatibleWith('license_officer',['billing_officer']), 'segregation on multiple role assignments');
verify(StaffRolePermissions::compatibleWith('support_agent',['auditor']), 'nonfinancial role combination');
verify(! StaffRolePermissions::mayGrantRole('staff_administrator', 'super_administrator', false, true),
    'staff admin cannot create super admin');
verify(! StaffRolePermissions::mayGrantRole('super_administrator', 'super_administrator', true, true),
    'super admin cannot elevate themselves');
verify(! StaffRolePermissions::mayGrantRole('staff_administrator','license_officer',false,false),
    'sensitive role requires independent review');
verify(StaffRolePermissions::mayGrantRole('staff_administrator','license_officer',false,true),
    'reviewed grant policy permits authorized staff admin');
verify(! StaffRolePermissions::mayApprove('staff-1','staff-1'), 'self approval never permitted');
verify(StaffRolePermissions::mayApprove('staff-1','staff-2'), 'separate reviewer allowed');
echo "PASS: separate central staff role template parity, least privilege, dual control and segregation\n";
