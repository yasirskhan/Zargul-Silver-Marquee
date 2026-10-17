<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\StaffRolePermissions;

require dirname(__DIR__).'/src/StaffRolePermissions.php';
require dirname(__DIR__).'/src/CentralStaffAccess.php';

function newId(): string {
    $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function requireTrue(bool $value, string $reason): void {
    if (!$value)throw new RuntimeException('FAIL central staff access '.$reason);
}
$dsn=getenv('CENTRAL_DB_DSN');
if (!$dsn||!str_contains($dsn,'hafzatech_central_test'))throw new RuntimeException('Test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customerA=newId();$customerB=newId();$staff=newId();
$stmt=$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)");
$stmt->execute(['id'=>$customerA,'name'=>'Support customer A']);
$stmt->execute(['id'=>$customerB,'name'=>'Support customer B']);
$db->prepare("INSERT INTO central_staff_users
    (id,email,display_name,password_hash,mfa_enrolled_at,status)
    VALUES(:id,'support.agent@example.test','Support Agent',:password,now(),'active')")
    ->execute(['id'=>$staff,'password'=>password_hash('synthetic-only-password',PASSWORD_ARGON2ID)]);
$db->prepare("INSERT INTO central_staff_role_grants
    (id,staff_id,role,customer_scope_id,reason)
    VALUES(:id,:staff,'support_agent',:customer,'Synthetic scoped role grant')")
    ->execute(['id'=>newId(),'staff'=>$staff,'customer'=>$customerA]);
$access=new CentralStaffAccess($db);
requireTrue(!$access->permits($staff,'support.reply',$customerA,false), 'MFA session proof required');
requireTrue($access->permits($staff,'support.reply',$customerA,true), 'in-scope staff access');
requireTrue(!$access->permits($staff,'support.reply',$customerB,true), 'foreign customer denied');
requireTrue(!$access->permits($staff,'billing.payment.verify',$customerA,true), 'support cannot verify funds');
$db->prepare("UPDATE central_staff_users SET status='suspended' WHERE id=:id")->execute(['id'=>$staff]);
requireTrue(!$access->permits($staff,'support.reply',$customerA,true), 'suspended account denied');
$db->prepare("UPDATE central_staff_users SET status='active' WHERE id=:id")->execute(['id'=>$staff]);
$db->prepare("UPDATE central_staff_role_grants SET revoked_at=now() WHERE staff_id=:id")
    ->execute(['id'=>$staff]);
requireTrue(!$access->permits($staff,'support.reply',$customerA,true), 'revoked grants denied');
echo "PASS: independently MFA-gated central staff scoped capabilities, cross-customer and stale access denial\n";
