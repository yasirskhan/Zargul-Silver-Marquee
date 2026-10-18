<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffPasswordVerifier;
use Hafzatech\Central\CentralStaffMfaChallenge;
use Hafzatech\Central\CentralStaffTotpVerifier;
use Hafzatech\Central\CentralStaffCredentialVerifier;
require dirname(__DIR__).'/src/CentralStaffPasswordVerifier.php';
require dirname(__DIR__).'/src/CentralStaffTotpVerifier.php';
require dirname(__DIR__).'/src/CentralStaffMfaChallenge.php';
require dirname(__DIR__).'/src/CentralStaffCredentialVerifier.php';

$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn || !str_contains($dsn,'hafzatech_central_test'))
 throw new RuntimeException('Dedicated central test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function credentialUuid():string{
 $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
 $x=bin2hex($b);return substr($x,0,8).'-'.substr($x,8,4).'-'.substr($x,12,4).'-'.substr($x,16,4).'-'.substr($x,20);
}
$id=credentialUuid();
$secret='12345678901234567890'; // Public RFC6238 SHA1 test seed.
$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,'credential-2fa@example.test','Synthetic Credential Verifier',:hash,
 decode(:sealed,'hex'),now(),'active')")
 ->execute(['id'=>$id,'hash'=>password_hash('synthetic-password-not-for-production',PASSWORD_DEFAULT),
 'sealed'=>bin2hex($secret)]);
$check=new CentralStaffCredentialVerifier(
 new CentralStaffPasswordVerifier($db),
 new CentralStaffMfaChallenge($db,static fn(string $cipher):string=>$cipher)
);
if($check->verify('credential-2fa@example.test','bad-password','287082',59)!==null)
 throw new RuntimeException('Password failure must deny before TOTP');
$q=$db->prepare('SELECT count(*) FROM central_staff_mfa_attempt_audit WHERE staff_id=:staff');
$q->execute(['staff'=>$id]);
if((int)$q->fetchColumn()!==0)
 throw new RuntimeException('Wrong password must never burn valid TOTP counter');
if($check->verify('credential-2fa@example.test','synthetic-password-not-for-production','wrong',59)!==null)
 throw new RuntimeException('Malformed OTP must deny');
if($check->verify('credential-2fa@example.test','synthetic-password-not-for-production','287082',59)!==$id)
 throw new RuntimeException('Both protected factors should succeed');
if($check->verify('credential-2fa@example.test','synthetic-password-not-for-production','287082',59)!==null)
 throw new RuntimeException('Same TOTP step replay must deny');
$q->execute(['staff'=>$id]);
if((int)$q->fetchColumn()!==2)
 throw new RuntimeException('Accepted MFA and replay rejection require audit events');
echo "PASS: independent vendor password + durable TOTP verifies both factors, prevents replay and never grants session\n";
