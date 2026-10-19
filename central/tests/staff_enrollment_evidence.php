<?php
declare(strict_types=1);
use Hafzatech\Central\CentralStaffIdentityAttestationVerifier;
use Hafzatech\Central\CentralStaffEnrollmentChallengeService;
use Hafzatech\Central\CentralStaffEnrollmentEvidenceService;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach([
 'StaffRolePermissions','CentralStaffAccess','CentralStaffPasswordVerifier',
 'CentralStaffTotpVerifier','CentralStaffMfaChallenge',
 'CentralStaffCredentialVerifier','CentralStaffLoginRateLimiter',
 'CentralStaffSessionService','CentralStaffIdentityAttestationVerifier',
 'CentralStaffEnrollmentChallengeService','CentralStaffEnrollmentEvidenceService'
] as $class)require dirname(__DIR__).'/src/'.$class.'.php';
function evidenceCheck(bool $v,string $reason):void {
    if(!$v)throw new RuntimeException('Enrollment evidence: '.$reason);
}
function evidenceDeny(callable $fn,string $code):void {
    try {$fn();}catch(RegistrationError $e) {
        evidenceCheck($e->errorCode===$code,
            'expected '.$code.' got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Missing expected denial '.$code);
}
function evidenceUuid():string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).
        '-'.substr($h,16,4).'-'.substr($h,20);
}
function evidenceTotp(string $seed):string {
    $n=intdiv(time(),30);
    $m=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),
        $seed,true);
    $p=ord($m[19])&15;
    $v=unpack('N',substr($m,$p,4))[1]&0x7fffffff;
    return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);
}
function evidenceB64(string $s):string {
    return rtrim(strtr(base64_encode($s),'+/','-_'),'=');
}
function evidenceEnvelope(array $payload,string $secret):array {
    $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    return ['key_id'=>'synthetic-provider','payload'=>evidenceB64($json),
        'signature'=>evidenceB64(sodium_crypto_sign_detached(
          'HAFZATECH-STAFF-IDENTITY-ATTESTATION-V1'."\n".$json,$secret))];
}
$dsn=getenv('CENTRAL_DB_DSN');
evidenceCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'dedicated HAFZATECH test PostgreSQL required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$issuer=evidenceUuid();$reviewer=evidenceUuid();$viewer=evidenceUuid();
$target=evidenceUuid();$targetStale=evidenceUuid();
$seed='12345678901234567890';$password='synthetic-evidence-password';
$insert=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,
  mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic attestation reviewer',:hash,
    decode(:seed,'hex'),:enrolled,:status)");
foreach([
 [$issuer,'evidence-issuer@example.test','active'],
 [$reviewer,'evidence-reviewer@example.test','active'],
 [$viewer,'evidence-viewer@example.test','active'],
 [$target,'evidence-target@example.test','pending_mfa'],
 [$targetStale,'evidence-stale@example.test','pending_mfa']
] as [$id,$email,$status]) {
    $insert->execute(['id'=>$id,'email'=>$email,
      'hash'=>password_hash($password,PASSWORD_ARGON2ID),
      'seed'=>$status==='active'?bin2hex($seed):'',
      'enrolled'=>$status==='active'?gmdate('Y-m-d H:i:sP'):null,
      'status'=>$status]);
}
$grant=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:id,:staff,'staff_administrator',
 'Synthetic evidence review independent global role')");
foreach([$issuer,$reviewer] as $id){
    $grant->execute(['id'=>evidenceUuid(),'staff'=>$id]);
}
// Viewer must hold a harmless role for the existing MFA session snapshot,
// but support_agent has no staff.create authority.
$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:id,:staff,'support_agent',
 'Synthetic unprivileged enrollment evidence viewer')")
 ->execute(['id'=>evidenceUuid(),'staff'=>$viewer]);
$provision=$db->prepare("INSERT INTO central_staff_provision_events
 (actor_staff_id,target_staff_id,action,reason)
 VALUES(:actor,:target,'created_pending_mfa','Synthetic evidence provenance')");
foreach([$target,$targetStale] as $id)
    $provision->execute(['actor'=>$issuer,'target'=>$id]);
$sessions=new Hafzatech\Central\CentralStaffSessionService($db,
 new Hafzatech\Central\CentralStaffCredentialVerifier(
  new Hafzatech\Central\CentralStaffPasswordVerifier($db),
  new Hafzatech\Central\CentralStaffMfaChallenge($db,
   static fn(string $cipher):string=>$cipher)),
 new Hafzatech\Central\CentralStaffLoginRateLimiter($db,str_repeat('V',32)));
$otp=evidenceTotp($seed);
$issuerBearer=$sessions->login('evidence-issuer@example.test',
 $password,$otp,'192.0.2.193');
$reviewerBearer=$sessions->login('evidence-reviewer@example.test',
 $password,$otp,'192.0.2.194');
$viewerBearer=$sessions->login('evidence-viewer@example.test',
 $password,$otp,'192.0.2.195');
evidenceCheck(is_string($issuerBearer)&&is_string($reviewerBearer)&&
 is_string($viewerBearer),'three current MFA sessions');
$access=new Hafzatech\Central\CentralStaffAccess($db);
$keypair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keypair);
$public=sodium_crypto_sign_publickey($keypair);
$verifier=new CentralStaffIdentityAttestationVerifier(
 $db,'synthetic-provider',$public,true);
$challenges=new CentralStaffEnrollmentChallengeService(
 $db,$sessions,$access,'synthetic-provider',true);
$on=new CentralStaffEnrollmentEvidenceService(
 $db,$sessions,$access,$verifier,true);
$off=new CentralStaffEnrollmentEvidenceService(
 $db,$sessions,$access,$verifier);
putenv('CENTRAL_STAFF_ENROLLMENT_CHALLENGE_ENABLED=true');
putenv('CENTRAL_STAFF_IDENTITY_ATTESTATION_ENABLED=true');
putenv('CENTRAL_STAFF_ENROLLMENT_EVIDENCE_ENABLED=false');
try {
 $c=$challenges->issue($issuerBearer,$target);
 $now=time();
 $payload=[
    'version'=>'v1','purpose'=>'HAFZATECH-STAFF-IDENTITY-ATTESTATION-V1',
    'staff_id'=>$target,
    'email_sha256'=>hash('sha256','evidence-target@example.test'),
    'nonce'=>$c['nonce'],
    'issued_at'=>$now,'expires_at'=>$now+240,
    'evidence_ref'=>'synthetic-operator-evidence-001'
 ];
 $proof=evidenceEnvelope($payload,$secret);
 evidenceDeny(fn()=>$on->checkAndRecord($reviewerBearer,$c['challenge_id'],
   $c['nonce'],$proof),'staff_enrollment_evidence_not_enabled');
 putenv('CENTRAL_STAFF_ENROLLMENT_EVIDENCE_ENABLED=true');
 // Cheap syntactic bounds reject oversized/invalid evidence before DB locks.
 evidenceDeny(fn()=>$on->checkAndRecord($reviewerBearer,$c['challenge_id'],
   str_repeat('x',200000),$proof),'invalid_staff_enrollment_evidence');
 evidenceDeny(fn()=>$on->checkAndRecord($reviewerBearer,$c['challenge_id'],
   $c['nonce'],array_replace($proof,['payload'=>str_repeat('A',5465)])),
   'invalid_staff_enrollment_evidence');
 evidenceDeny(fn()=>$on->checkAndRecord($reviewerBearer,$c['challenge_id'],
   $c['nonce'],array_replace($proof,['signature'=>str_repeat('A',87)])),
   'invalid_staff_enrollment_evidence');

 evidenceDeny(fn()=>$off->checkAndRecord($reviewerBearer,$c['challenge_id'],
   $c['nonce'],$proof),'staff_enrollment_evidence_not_enabled');
 evidenceDeny(fn()=>$on->checkAndRecord($issuerBearer,$c['challenge_id'],
   $c['nonce'],$proof),'staff_enrollment_evidence_denied');
 evidenceDeny(fn()=>$on->checkAndRecord($viewerBearer,$c['challenge_id'],
   $c['nonce'],$proof),'staff_enrollment_evidence_denied');
 evidenceDeny(fn()=>$on->checkAndRecord($reviewerBearer,$c['challenge_id'],
   evidenceB64(random_bytes(32)),$proof),'invalid_staff_enrollment_evidence');
 evidenceDeny(fn()=>$on->checkAndRecord($reviewerBearer,$c['challenge_id'],
   $c['nonce'],array_replace($proof,['signature'=>evidenceB64(random_bytes(64))])),
   'invalid_staff_identity_attestation');
 $result=$on->checkAndRecord($reviewerBearer,$c['challenge_id'],$c['nonce'],$proof);
 evidenceCheck($result['staff_id']===$target &&
    $result['activation_allowed']===false &&
    preg_match('/^[a-f0-9-]{36}$/D',$result['evidence_check_id'])===1,
    'independent current global MFA reviewer records safe evidence receipt only');
 evidenceDeny(fn()=>$on->checkAndRecord($reviewerBearer,$c['challenge_id'],
   $c['nonce'],$proof),'staff_enrollment_evidence_already_checked');
 $q=$db->prepare("SELECT reviewed_by_staff_id,signature_sha256,decision
   FROM central_staff_enrollment_evidence_checks WHERE id=:id");
 $q->execute(['id'=>$result['evidence_check_id']]);
 $saved=$q->fetch(PDO::FETCH_ASSOC);
 evidenceCheck($saved['reviewed_by_staff_id']===$reviewer &&
    $saved['decision']==='signature_checked_only' &&
    $saved['signature_sha256']===hash('sha256',$proof['payload']."\n".$proof['signature']) &&
    !str_contains(json_encode($saved),$proof['payload']),
    'audit persists digest and independent MFA actor, not raw signed evidence');
 try{
   $db->prepare('DELETE FROM central_staff_enrollment_evidence_checks
      WHERE id=:id')->execute(['id'=>$result['evidence_check_id']]);
   throw new RuntimeException('Unexpected mutable evidence journal');
 }catch(PDOException $e){
   evidenceCheck($e->getCode()==='23514','immutable evidence journal');
 }
 $q=$db->prepare('SELECT status,mfa_enrolled_at FROM central_staff_users WHERE id=:id');
 $q->execute(['id'=>$target]);$state=$q->fetch(PDO::FETCH_ASSOC);
 evidenceCheck($state['status']==='pending_mfa' &&
    $state['mfa_enrolled_at']===null,
    'signed provider receipt never activates account or MFA');
 $stale=$challenges->issue($issuerBearer,$targetStale);
 $now=time();
 $claim=array_replace($payload,[
    'staff_id'=>$targetStale,'email_sha256'=>hash('sha256','evidence-stale@example.test'),
    'nonce'=>$stale['nonce'],'issued_at'=>$now,'expires_at'=>$now+240
 ]);
 // A second independently MFA-authenticated reviewer held valid GLOBAL
 // staff.create authority when the challenge was issued. Revoking that
 // authority must deny review before any evidence receipt is created.
 $revokedReviewer=evidenceUuid();
 $insert->execute(['id'=>$revokedReviewer,
    'email'=>'evidence-revoked-reviewer@example.test',
    'hash'=>password_hash($password,PASSWORD_ARGON2ID),
    'seed'=>bin2hex($seed),
    'enrolled'=>gmdate('Y-m-d H:i:sP'),'status'=>'active']);
 $grant->execute(['id'=>evidenceUuid(),'staff'=>$revokedReviewer]);
 $revokedBearer=$sessions->login('evidence-revoked-reviewer@example.test',
    $password,$otp,'192.0.2.196');
 evidenceCheck(is_string($revokedBearer) &&
    $access->permits($revokedReviewer,'staff.create',null,true),
    'second reviewer begins with valid independent GLOBAL MFA role');
 $db->prepare('UPDATE central_staff_role_grants SET revoked_at=now()
    WHERE staff_id=:id')->execute(['id'=>$revokedReviewer]);
 evidenceDeny(fn()=>$on->checkAndRecord($revokedBearer,$stale['challenge_id'],
    $stale['nonce'],evidenceEnvelope($claim,$secret)),
    'staff_enrollment_evidence_denied');
 $count=$db->prepare('SELECT count(*) FROM central_staff_enrollment_evidence_checks
     WHERE challenge_id=:id');
 $count->execute(['id'=>$stale['challenge_id']]);
 evidenceCheck((int)$count->fetchColumn()===0,
    'revoked reviewer cannot persist a proof-check receipt');
 $db->prepare('UPDATE central_staff_role_grants SET revoked_at=now()
    WHERE staff_id=:id')->execute(['id'=>$issuer]);
 evidenceDeny(fn()=>$on->checkAndRecord($reviewerBearer,$stale['challenge_id'],
    $stale['nonce'],evidenceEnvelope($claim,$secret)),
    'staff_enrollment_evidence_denied');
} finally {
 putenv('CENTRAL_STAFF_ENROLLMENT_CHALLENGE_ENABLED=false');
 putenv('CENTRAL_STAFF_IDENTITY_ATTESTATION_ENABLED=false');
 putenv('CENTRAL_STAFF_ENROLLMENT_EVIDENCE_ENABLED=false');
}
echo "PASS: independent MFA evidence check, one-use journal and stale issuer denial, no staff activation\n";
