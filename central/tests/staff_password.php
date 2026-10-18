<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffPasswordVerifier;
require dirname(__DIR__).'/src/CentralStaffPasswordVerifier.php';
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn || !str_contains($dsn,'hafzatech_central_test'))
    throw new RuntimeException('Isolated central DB required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function passwordUuid():string{
 $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
 $s=bin2hex($b);return substr($s,0,8).'-'.substr($s,8,4).'-'.substr($s,12,4).'-'.substr($s,16,4).'-'.substr($s,20);
}
$active=passwordUuid();$suspended=passwordUuid();$pending=passwordUuid();
$q=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Password Test',:hash,decode('41414141414141414141414141414141','hex'),:enrolled,:status)");
foreach([
 [$active,'central-password-active@example.test','active',true],
 [$suspended,'central-password-suspended@example.test','suspended',true],
 [$pending,'central-password-pending@example.test','active',false],
] as [$id,$mail,$status,$enrolled]){
 $q->execute(['id'=>$id,'email'=>$mail,
 'hash'=>password_hash('synthetic-long-protected-password',PASSWORD_DEFAULT),
 'enrolled'=>$enrolled?gmdate('Y-m-d H:i:s+00'):null,'status'=>$status]);
}
$v=new CentralStaffPasswordVerifier($db);
if($v->verify('central-password-active@example.test','synthetic-long-protected-password')!==$active)
 throw new RuntimeException('Valid vendor identity password should pass to MFA step');
foreach([
 ['central-password-active@example.test','wrong'],
 ['central-password-suspended@example.test','synthetic-long-protected-password'],
 ['central-password-pending@example.test','synthetic-long-protected-password'],
 ['CENTRAL-PASSWORD-ACTIVE@EXAMPLE.TEST','synthetic-long-protected-password'],
 ['unknown@example.test','synthetic-long-protected-password'],
 ['not an email','synthetic-long-protected-password'],
] as [$mail,$password]){
 if($v->verify($mail,$password)!==null)
    throw new RuntimeException('Invalid, disabled or untrusted password step accepted');
}
echo "PASS: centrally isolated password identity verification, disabled and MFA-pending denial\n";
