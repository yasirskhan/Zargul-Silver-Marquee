<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffEnrollmentChallengeService;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach([
 'StaffRolePermissions','CentralStaffAccess','CentralStaffPasswordVerifier',
 'CentralStaffTotpVerifier','CentralStaffMfaChallenge',
 'CentralStaffCredentialVerifier','CentralStaffLoginRateLimiter',
 'CentralStaffSessionService','CentralStaffEnrollmentChallengeService'
] as $class)require dirname(__DIR__).'/src/'.$class.'.php';
function challengeCheck(bool $v,string $reason):void {
    if(!$v)throw new RuntimeException('Staff enrollment challenge: '.$reason);
}
function challengeDeny(callable $fn,string $code):void {
    try {$fn();} catch(RegistrationError $e) {
        challengeCheck($e->errorCode===$code,
            'expected '.$code.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Expected denial '.$code);
}
function challengeUuid():string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).
        '-'.substr($h,16,4).'-'.substr($h,20);
}
function challengeTotp(string $seed):string {
    $step=intdiv(time(),30);
    $mac=hash_hmac('sha1',
       pack('N2',intdiv($step,4294967296),$step%4294967296),$seed,true);
    $p=ord($mac[19])&15;
    $v=unpack('N',substr($mac,$p,4))[1]&0x7fffffff;
    return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
challengeCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
 'isolated PostgreSQL test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin=challengeUuid();$viewer=challengeUuid();
$target=challengeUuid();$unprovenanced=challengeUuid();$active=challengeUuid();
$seed='12345678901234567890';$password='synthetic-enrollment-admin-password';
$insert=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,
  mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic enrollment actor',:hash,
  decode(:mfa,'hex'),:enrolled,:status)");
foreach([
 [$admin,'enrollment-admin@example.test','active'],
 [$viewer,'enrollment-viewer@example.test','active'],
 [$target,'enrollment-target@example.test','pending_mfa'],
 [$unprovenanced,'enrollment-no-provenance@example.test','pending_mfa'],
 [$active,'enrollment-active@example.test','active']
] as [$id,$email,$status]){
    $insert->execute(['id'=>$id,'email'=>$email,
      'hash'=>password_hash($password,PASSWORD_ARGON2ID),
      'mfa'=>$status==='active'?bin2hex($seed):'',
      'enrolled'=>$status==='active'?gmdate('Y-m-d H:i:sP'):null,
      'status'=>$status]);
}
$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:id,:actor,'staff_administrator',
 'Synthetic independent global staff administration')")
 ->execute(['id'=>challengeUuid(),'actor'=>$admin]);
// The unprivileged test viewer needs its own active role to obtain an MFA
// session, without receiving the staff.create capability under test.
$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:id,:staff,'support_agent',
 'Synthetic read-only staff session fixture')")
 ->execute(['id'=>challengeUuid(),'staff'=>$viewer]);
$event=$db->prepare("INSERT INTO central_staff_provision_events
 (actor_staff_id,target_staff_id,action,reason)
 VALUES(:actor,:target,'created_pending_mfa','Synthetic enrollment provenance')");
foreach([$target,$active] as $id)$event->execute(['actor'=>$admin,'target'=>$id]);
$sessions=new Hafzatech\Central\CentralStaffSessionService($db,
 new Hafzatech\Central\CentralStaffCredentialVerifier(
  new Hafzatech\Central\CentralStaffPasswordVerifier($db),
  new Hafzatech\Central\CentralStaffMfaChallenge($db,
   static fn(string $cipher):string=>$cipher)),
 new Hafzatech\Central\CentralStaffLoginRateLimiter($db,str_repeat('E',32)));
$otp=challengeTotp($seed);
$adminBearer=$sessions->login('enrollment-admin@example.test',
 $password,$otp,'192.0.2.191');
$viewerBearer=$sessions->login('enrollment-viewer@example.test',
 $password,$otp,'192.0.2.192');
challengeCheck(is_string($adminBearer)&&is_string($viewerBearer),
 'synthetic protected MFA sessions');
$access=new Hafzatech\Central\CentralStaffAccess($db);
$on=new CentralStaffEnrollmentChallengeService(
 $db,$sessions,$access,'synthetic-provider',true);
$off=new CentralStaffEnrollmentChallengeService(
 $db,$sessions,$access,'synthetic-provider');
putenv('CENTRAL_STAFF_ENROLLMENT_CHALLENGE_ENABLED=false');
challengeDeny(fn()=>$on->issue($adminBearer,$target),
 'staff_enrollment_challenge_not_enabled');
putenv('CENTRAL_STAFF_ENROLLMENT_CHALLENGE_ENABLED=true');
try {
    challengeDeny(fn()=>$off->issue($adminBearer,$target),
        'staff_enrollment_challenge_not_enabled');
    challengeDeny(fn()=>$on->issue($viewerBearer,$target),
        'staff_enrollment_challenge_denied');
    challengeDeny(fn()=>$on->issue(str_repeat('0',64),$target),
        'staff_enrollment_challenge_denied');
    challengeDeny(fn()=>$on->issue($adminBearer,$admin),
        'staff_enrollment_challenge_denied');
    challengeDeny(fn()=>$on->issue($adminBearer,'invalid-uuid'),
        'invalid_staff_enrollment_challenge');
    challengeDeny(fn()=>$on->issue($adminBearer,$unprovenanced),
        'staff_enrollment_challenge_unavailable');
    challengeDeny(fn()=>$on->issue($adminBearer,$active),
        'staff_enrollment_challenge_unavailable');
    $issued=$on->issue($adminBearer,$target);
    challengeCheck(array_keys($issued)===
        ['challenge_id','staff_id','nonce','activation_allowed'] &&
        $issued['staff_id']===$target &&
        $issued['activation_allowed']===false &&
        strlen($issued['nonce'])===43,
        'issues a bounded nonce, never an activation');
    $q=$db->prepare("SELECT issued_by_staff_id,target_staff_id,
        email_sha256,nonce_sha256,provider_key_id,
        expires_at-issued_at AS validity
        FROM central_staff_enrollment_challenges WHERE id=:id");
    $q->execute(['id'=>$issued['challenge_id']]);$rec=$q->fetch(PDO::FETCH_ASSOC);
    challengeCheck($rec['issued_by_staff_id']===$admin &&
       $rec['target_staff_id']===$target &&
       $rec['provider_key_id']==='synthetic-provider' &&
       $rec['email_sha256']===hash('sha256','enrollment-target@example.test') &&
       $rec['nonce_sha256']===hash('sha256',$issued['nonce']) &&
       !str_contains(json_encode($rec),$issued['nonce']),
       'immutable hash-only nonce and current email binding with actor attribution');
    challengeDeny(fn()=>$on->issue($adminBearer,$target),
        'staff_enrollment_challenge_pending');
    try{
        $db->prepare('UPDATE central_staff_enrollment_challenges
            SET expires_at=expires_at WHERE id=:id')
            ->execute(['id'=>$issued['challenge_id']]);
        throw new RuntimeException('Unexpected mutable challenge');
    } catch(PDOException $e) {
        challengeCheck($e->getCode()==='23514',
          'PostgreSQL rejects altering issued challenge');
    }
    $q=$db->prepare('SELECT status,mfa_enrolled_at FROM central_staff_users
        WHERE id=:id');$q->execute(['id'=>$target]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    challengeCheck($row['status']==='pending_mfa' &&
        $row['mfa_enrolled_at']===null,
        'challenge never activates credentials');
    // Immutable synthetic fixtures emulate EXPIRED, not deleted/replayed,
    // challenges. The rolling 24-hour count must still block rapid reissue.
    $limited=challengeUuid();$oldEnough=challengeUuid();
    foreach([[$limited,'enrollment-limited@example.test'],
             [$oldEnough,'enrollment-old@example.test']] as [$who,$email]) {
        $insert->execute(['id'=>$who,'email'=>$email,
            'hash'=>password_hash($password,PASSWORD_ARGON2ID),
            'mfa'=>'','enrolled'=>null,'status'=>'pending_mfa']);
        $event->execute(['actor'=>$admin,'target'=>$who]);
    }
    $inject=$db->prepare(
        "INSERT INTO central_staff_enrollment_challenges
         (id,target_staff_id,issued_by_staff_id,provider_key_id,
          email_sha256,nonce_sha256,issued_at,expires_at)
         VALUES(:id,:target,:issuer,'synthetic-provider',:email,:nonce,
                now()-(:age)::interval,now()-(:age)::interval+interval '5 minutes')"
    );
    foreach([[$limited,'1 hour'],[$oldEnough,'2 days']] as [$who,$age]){
        for($i=0;$i<5;$i++){
            $inject->execute([
                'id'=>challengeUuid(),'target'=>$who,'issuer'=>$admin,
                'email'=>hash('sha256',$who===$limited
                    ? 'enrollment-limited@example.test'
                    : 'enrollment-old@example.test'),
                'nonce'=>hash('sha256',random_bytes(32)),'age'=>$age]);
        }
    }
    challengeDeny(fn()=>$on->issue($adminBearer,$limited),
        'staff_enrollment_challenge_rate_limited');
    $oldIssue=$on->issue($adminBearer,$oldEnough);
    challengeCheck($oldIssue['staff_id']===$oldEnough &&
        $oldIssue['activation_allowed']===false,
        'expired older-than-24-hour events no longer consume issuance cap');
    $check=$db->prepare('SELECT count(*) FROM central_staff_enrollment_challenges
        WHERE target_staff_id=:target');
    $check->execute(['target'=>$limited]);
    challengeCheck((int)$check->fetchColumn()===5,
        'rate-limited request never adds a new challenge audit record');
    // A compromised GLOBAL issuer must not bypass recipient caps by
    // spraying short-lived challenges across many different recipients.
    // There are 12 issuer events above (one target, five limited, five
    // older-than-24-hours, one reissue); only seven are in the last day.
    // Inject thirteen more recent immutable events to reach the issuer cap.
    $issuerTarget=challengeUuid();
    $insert->execute(['id'=>$issuerTarget,
        'email'=>'enrollment-issuer-cap@example.test',
        'hash'=>password_hash($password,PASSWORD_ARGON2ID),
        'mfa'=>'','enrolled'=>null,'status'=>'pending_mfa']);
    $event->execute(['actor'=>$admin,'target'=>$issuerTarget]);
    for($i=0;$i<13;$i++) {
        $inject->execute(['id'=>challengeUuid(),'target'=>$limited,
            'issuer'=>$admin,
            'email'=>hash('sha256','enrollment-limited@example.test'),
            'nonce'=>hash('sha256',random_bytes(32)),'age'=>'1 hour']);
    }
    challengeDeny(fn()=>$on->issue($adminBearer,$issuerTarget),
        'staff_enrollment_issuer_rate_limited');
    $check->execute(['target'=>$issuerTarget]);
    challengeCheck((int)$check->fetchColumn()===0,
        'issuer cap denies before writing challenge for a fresh target');
    $db->prepare('UPDATE central_staff_role_grants SET revoked_at=now()
        WHERE staff_id=:id')->execute(['id'=>$admin]);
    challengeDeny(fn()=>$on->issue($adminBearer,challengeUuid()),
        'staff_enrollment_challenge_denied');
} finally {
    putenv('CENTRAL_STAFF_ENROLLMENT_CHALLENGE_ENABLED=false');
}
echo "PASS: global MFA only, pending provenance, nonce digest journal, immutable challenge, no credential activation\n";
