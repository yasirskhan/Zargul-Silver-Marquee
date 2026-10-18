<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\CentralStaffMfaAuditReader;
use Hafzatech\Central\StaffRolePermissions;

require dirname(__DIR__).'/src/StaffRolePermissions.php';
require dirname(__DIR__).'/src/CentralStaffAccess.php';
require dirname(__DIR__).'/src/CentralStaffMfaAuditReader.php';

$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn || !str_contains($dsn,'hafzatech_central_test'))throw new RuntimeException('Dedicated DB required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function auditUuid():string {
 $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
 $x=bin2hex($b);return substr($x,0,8).'-'.substr($x,8,4).'-'.substr($x,12,4).'-'.substr($x,16,4).'-'.substr($x,20);
}
$actor=auditUuid();$other=auditUuid();$subject=auditUuid();
$make=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'MFA Auditor Fixture',:hash,decode('41414141414141414141414141414141','hex'),now(),'active')");
foreach([[$actor,'mfa-auditor@example.test'],[$other,'mfa-auditor-denied@example.test'],[$subject,'mfa-auditor-target@example.test']] as [$id,$email]){
 $make->execute(['id'=>$id,'email'=>$email,'hash'=>password_hash('synthetic-password',PASSWORD_DEFAULT)]);
}
$db->prepare("INSERT INTO central_staff_role_grants(id,staff_id,role,reason)
 VALUES(:grant,:id,'auditor','Auditor role test fixture')")
 ->execute(['grant'=>auditUuid(),'id'=>$actor]);
$write=$db->prepare("INSERT INTO central_staff_mfa_attempt_audit(staff_id,outcome,accepted_counter)
 VALUES(:id,:result,:counter)");
foreach([['rejected',null],['accepted',100],['locked',null]] as [$result,$counter]){
 $write->execute(['id'=>$subject,'result'=>$result,'counter'=>$counter]);
}
$reader=new CentralStaffMfaAuditReader($db,new CentralStaffAccess($db));
$list=$reader->listForStaff($actor,$subject,true,0,2);
if(count($list)!==2 || $list[0]['outcome']!=='locked' || $list[1]['outcome']!=='accepted')
 throw new RuntimeException('MFA audit ordering and page limit failed');
$older=$reader->listForStaff($actor,$subject,true,(int)$list[1]['id'],25);
if(count($older)!==1 || $older[0]['outcome']!=='rejected')
 throw new RuntimeException('MFA audit cursor failed');
foreach([[$actor,false],[$other,true]] as [$id,$mfa]){
 try{$reader->listForStaff($id,$subject,$mfa);throw new RuntimeException('FAIL unauthorized MFA audit read');}
 catch(RuntimeException $e){if($e->getMessage()==='FAIL unauthorized MFA audit read')throw $e;}
}
echo "PASS: vendor-only MFA audit history, cursor, trusted staff MFA and denied unauthorized roles\n";
