<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffEnrollmentAuditReader;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach([
 'StaffRolePermissions','CentralStaffAccess','CentralStaffPasswordVerifier',
 'CentralStaffTotpVerifier','CentralStaffMfaChallenge',
 'CentralStaffCredentialVerifier','CentralStaffLoginRateLimiter',
 'CentralStaffSessionService','CentralStaffSessionAuthorization',
 'CentralStaffEnrollmentAuditReader'
] as $class)require dirname(__DIR__).'/src/'.$class.'.php';
function auditEnrollmentCheck(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('Enrollment audit: '.$reason);
}
function auditEnrollmentDeny(callable $f,string $code):void {
    try {$f();}catch(RegistrationError $e) {
        auditEnrollmentCheck($e->errorCode===$code,
           'expected '.$code.' got '.$e->errorCode);return;
    }
    throw new RuntimeException('Missing denial '.$code);
}
function auditEnrollmentUuid():string{
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).
        '-'.substr($h,16,4).'-'.substr($h,20);
}
function auditEnrollmentTotp(string $seed):string {
    $n=intdiv(time(),30);
    $m=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),
        $seed,true);
    $p=ord($m[19])&15;$v=unpack('N',substr($m,$p,4))[1]&0x7fffffff;
    return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
auditEnrollmentCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
 'isolated synthetic PostgreSQL required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$auditor=auditEnrollmentUuid();$viewer=auditEnrollmentUuid();
$issuer=auditEnrollmentUuid();$reviewer=auditEnrollmentUuid();$target=auditEnrollmentUuid();$other=auditEnrollmentUuid();
$seed='12345678901234567890';$password='synthetic-enrollment-auditor-password';
$create=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic enrollment audit fixture',:hash,
 decode(:mfa,'hex'),:enrolled,:status)");
foreach([
 [$auditor,'enroll-audit-auditor@example.test','active'],
 [$viewer,'enroll-audit-viewer@example.test','active'],
 [$issuer,'enroll-audit-issuer@example.test','active'],
 [$reviewer,'enroll-audit-reviewer@example.test','active'],
 [$target,'enroll-audit-target@example.test','pending_mfa'],
 [$other,'enroll-audit-other@example.test','pending_mfa']
] as [$id,$email,$status]) {
 $create->execute(['id'=>$id,'email'=>$email,
 'hash'=>password_hash($password,PASSWORD_ARGON2ID),
 'mfa'=>$status==='active'?bin2hex($seed):'',
 'enrolled'=>$status==='active'?gmdate('Y-m-d H:i:sP'):null,
 'status'=>$status]);
}
$grant=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:id,:staff,:role,
 'Synthetic vendor enrollment audit access')");
foreach([[$auditor,'auditor'],[$viewer,'support_agent'],
 [$issuer,'staff_administrator'],[$reviewer,'staff_administrator']] as [$id,$role])
 $grant->execute(['id'=>auditEnrollmentUuid(),'staff'=>$id,'role'=>$role]);
$db->prepare("INSERT INTO central_staff_provision_events
 (actor_staff_id,target_staff_id,action,reason)
 VALUES(:actor,:target,'created_pending_mfa','Synthetic audit provenance')")
 ->execute(['actor'=>$issuer,'target'=>$target]);
$nonce1=bin2hex(random_bytes(32));$nonce2=bin2hex(random_bytes(32));
$challenge=$db->prepare("INSERT INTO central_staff_enrollment_challenges
 (id,target_staff_id,issued_by_staff_id,provider_key_id,
 email_sha256,nonce_sha256,expires_at)
 VALUES(:id,:target,:actor,'synthetic-provider',:email,:nonce,
 now()+interval '5 minutes')");
$a=auditEnrollmentUuid();$b=auditEnrollmentUuid();
foreach([[$a,$nonce1],[$b,$nonce2]] as [$id,$nonce]){
 $challenge->execute(['id'=>$id,'target'=>$target,'actor'=>$issuer,
 'email'=>hash('sha256','enroll-audit-target@example.test'),
 'nonce'=>hash('sha256',$nonce)]);
}
$checkId=auditEnrollmentUuid();
$db->prepare("INSERT INTO central_staff_enrollment_evidence_checks
 (id,challenge_id,reviewed_by_staff_id,signature_sha256,decision)
 VALUES(:id,:challenge,:reviewer,:digest,'signature_checked_only')")
 ->execute(['id'=>$checkId,'challenge'=>$a,'reviewer'=>$reviewer,
 'digest'=>hash('sha256','synthetic-signed-evidence')]);
$sessions=new Hafzatech\Central\CentralStaffSessionService($db,
 new Hafzatech\Central\CentralStaffCredentialVerifier(
  new Hafzatech\Central\CentralStaffPasswordVerifier($db),
  new Hafzatech\Central\CentralStaffMfaChallenge($db,
   static fn(string $cipher):string=>$cipher)),
 new Hafzatech\Central\CentralStaffLoginRateLimiter($db,str_repeat('A',32)));
$otp=auditEnrollmentTotp($seed);
$auditorBearer=$sessions->login('enroll-audit-auditor@example.test',
 $password,$otp,'192.0.2.196');
$viewerBearer=$sessions->login('enroll-audit-viewer@example.test',
 $password,$otp,'192.0.2.197');
auditEnrollmentCheck(is_string($auditorBearer)&&is_string($viewerBearer),
 'MFA synthetic auditor and support sessions');
$reader=new CentralStaffEnrollmentAuditReader(
 $db,new Hafzatech\Central\CentralStaffSessionAuthorization(
 $sessions,new Hafzatech\Central\CentralStaffAccess($db)));
auditEnrollmentDeny(fn()=>$reader->listForTarget($auditorBearer,'bad-id'),
 'invalid_staff_enrollment_audit_request');
auditEnrollmentDeny(fn()=>$reader->listForTarget($auditorBearer,$target,26),
 'invalid_staff_enrollment_audit_request');
auditEnrollmentDeny(fn()=>$reader->listForTarget($viewerBearer,$target),
 'staff_enrollment_audit_denied');
auditEnrollmentDeny(fn()=>$reader->listForTarget(str_repeat('0',64),$target),
 'staff_enrollment_audit_denied');
$history=$reader->listForTarget($auditorBearer,$target);
auditEnrollmentCheck(count($history)===2 &&
 $reader->listForTarget($auditorBearer,$target,1)!==[] &&
 $reader->listForTarget($auditorBearer,$other)===[],
 'auditor sees bounded exact-target events only');
$byId=array_column($history,null,'challenge_id');
auditEnrollmentCheck(array_keys($byId[$a])===[
 'challenge_id','target_staff_id','issued_by_staff_id','provider_key_id',
 'issued_at','expires_at','reviewed_by_staff_id','evidence_check',
 'evidence_checked_at'] &&
 $byId[$a]['target_staff_id']===$target &&
 $byId[$a]['issued_by_staff_id']===$issuer &&
 $byId[$a]['reviewed_by_staff_id']===$reviewer &&
 $byId[$a]['evidence_check']==='signature_checked_only' &&
 $byId[$b]['reviewed_by_staff_id']===null &&
 $byId[$b]['evidence_check']===null &&
 !str_contains(json_encode($history),$nonce1) &&
 !str_contains(json_encode($history),'signature_sha256') &&
 !str_contains(json_encode($history),'email_sha256'),
 'audit allowlist contains no nonce, hashes, personal email or signatures');
$db->prepare('UPDATE central_staff_role_grants SET revoked_at=now()
 WHERE staff_id=:staff')->execute(['staff'=>$auditor]);
auditEnrollmentDeny(fn()=>$reader->listForTarget($auditorBearer,$target),
 'staff_enrollment_audit_denied');
echo "PASS: protected MFA auditor can inspect bounded synthetic enrollment history without secret material\n";
