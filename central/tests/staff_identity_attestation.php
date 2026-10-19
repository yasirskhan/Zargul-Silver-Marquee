<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffIdentityAttestationVerifier;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/CentralStaffIdentityAttestationVerifier.php';

function attestationCheck(bool $value,string $message):void {
    if(!$value)throw new RuntimeException('Staff identity attestation: '.$message);
}
function attestationDeny(callable $call,string $code):void {
    try {$call();} catch(RegistrationError $e) {
        attestationCheck($e->errorCode===$code,
            'expected '.$code.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Missing denial '.$code);
}
function attestationUuid():string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).
        '-'.substr($h,16,4).'-'.substr($h,20);
}
function attestationB64(string $b):string {
    return rtrim(strtr(base64_encode($b),'+/','-_'),'=');
}
function attestationSign(array $data,string $secret,string $keyId='synthetic-provider'):array {
    $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    return [
        'key_id'=>$keyId,
        'payload'=>attestationB64($json),
        'signature'=>attestationB64(sodium_crypto_sign_detached(
            'HAFZATECH-STAFF-IDENTITY-ATTESTATION-V1'."\n".$json,$secret))
    ];
}
$dsn=getenv('CENTRAL_DB_DSN');
attestationCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'isolated PostgreSQL database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$id=attestationUuid();
$actor=attestationUuid();
$unprovenanced=attestationUuid();
$active=attestationUuid();
$insert=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,status)
 VALUES(:id,:email,'Synthetic identity attestation',:hash,:status)");
foreach([
    [$actor,'attest-actor@example.test','active'],
    [$id,'attest-target@example.test','pending_mfa'],
    [$unprovenanced,'attest-unknown@example.test','pending_mfa'],
    [$active,'attest-active@example.test','active']
] as [$who,$email,$status]) {
    $insert->execute(['id'=>$who,'email'=>$email,'status'=>$status,
        'hash'=>password_hash(bin2hex(random_bytes(32)),PASSWORD_ARGON2ID)]);
}
$db->prepare("INSERT INTO central_staff_provision_events
 (actor_staff_id,target_staff_id,action,reason)
 VALUES(:actor,:target,'created_pending_mfa','Synthetic supervised fixture')")
 ->execute(['actor'=>$actor,'target'=>$id]);
$db->prepare("INSERT INTO central_staff_provision_events
 (actor_staff_id,target_staff_id,action,reason)
 VALUES(:actor,:target,'created_pending_mfa','Synthetic active denial fixture')")
 ->execute(['actor'=>$actor,'target'=>$active]);

$keypair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keypair);
$public=sodium_crypto_sign_publickey($keypair);
$now=time();
$nonce=attestationB64(random_bytes(32));
$data=[
 'version'=>'v1','purpose'=>'HAFZATECH-STAFF-IDENTITY-ATTESTATION-V1',
 'staff_id'=>$id,'email_sha256'=>hash('sha256','attest-target@example.test'),
 'nonce'=>$nonce,'issued_at'=>$now,'expires_at'=>$now+240,
 'evidence_ref'=>'synthetic-proof-reference-001'
];
$envelope=attestationSign($data,$secret);
$off=new CentralStaffIdentityAttestationVerifier(
 $db,'synthetic-provider',$public);
$on=new CentralStaffIdentityAttestationVerifier(
 $db,'synthetic-provider',$public,true);
putenv('CENTRAL_STAFF_IDENTITY_ATTESTATION_ENABLED=false');
attestationDeny(fn()=>$on->inspect($id,$nonce,$envelope,$now),
 'staff_identity_attestation_not_enabled');
putenv('CENTRAL_STAFF_IDENTITY_ATTESTATION_ENABLED=true');
try {
    attestationDeny(fn()=>$off->inspect($id,$nonce,$envelope,$now),
        'staff_identity_attestation_not_enabled');
    $verified=$on->inspect($id,$nonce,$envelope,$now);
    attestationCheck($verified===[
        'staff_id'=>$id,'attestation_signature_valid'=>true,
        'activation_allowed'=>false],
        'synthetic signature only validates evidence against pending record; no activation');
    $bad=static function(array $claim) use ($on,$id,$nonce,$now,$secret):void {
        attestationDeny(fn()=>$on->inspect($id,$nonce,
            attestationSign($claim,$secret),$now),
            'invalid_staff_identity_attestation');
    };
    $bad(array_replace($data,['staff_id'=>$active]));
    $bad(array_replace($data,['email_sha256'=>hash('sha256','substituted@example.test')]));
    $bad(array_replace($data,['nonce'=>attestationB64(random_bytes(32))]));
    $bad(array_replace($data,['issued_at'=>$now+1]));
    $bad(array_replace($data,['issued_at'=>$now-301,'expires_at'=>$now+1]));
    $bad(array_replace($data,['expires_at'=>$now]));
    $bad(array_replace($data,['expires_at'=>$now+301]));
    $bad(array_replace($data,['unexpected_approval'=>true]));
    $bad(array_replace($data,['purpose'=>'unrelated-proof']));
    $bad(array_replace($data,['evidence_ref'=>'invalid']));
    $bad(array_replace($data,['issued_at'=>(string)$now]));
    attestationDeny(fn()=>$on->inspect($unprovenanced,$nonce,
        attestationSign(array_replace($data,['staff_id'=>$unprovenanced,
            'email_sha256'=>hash('sha256','attest-unknown@example.test')]),$secret),$now),
        'invalid_staff_identity_attestation');
    attestationDeny(fn()=>$on->inspect($active,$nonce,
        attestationSign(array_replace($data,['staff_id'=>$active,
            'email_sha256'=>hash('sha256','attest-active@example.test')]),$secret),$now),
        'invalid_staff_identity_attestation');
    attestationDeny(fn()=>$on->inspect(attestationUuid(),$nonce,$envelope,$now),
        'invalid_staff_identity_attestation');
    attestationDeny(fn()=>$on->inspect($id,attestationB64(random_bytes(32)),
        $envelope,$now),'invalid_staff_identity_attestation');
    attestationDeny(fn()=>$on->inspect($id,'not-a-nonce',$envelope,$now),
        'invalid_staff_identity_attestation');
    attestationDeny(fn()=>$on->inspect($id,$nonce,
        array_replace($envelope,['key_id'=>'other-provider']),$now),
        'invalid_staff_identity_attestation');
    attestationDeny(fn()=>$on->inspect($id,$nonce,
        array_replace($envelope,['signature'=>attestationB64(random_bytes(64))]),$now),
        'invalid_staff_identity_attestation');
    attestationDeny(fn()=>$on->inspect($id,$nonce,
        $envelope+['activate'=>true],$now),
        'invalid_staff_identity_attestation');
    $other=sodium_crypto_sign_keypair();
    $notTrusted=new CentralStaffIdentityAttestationVerifier($db,
        'synthetic-provider',sodium_crypto_sign_publickey($other),true);
    attestationDeny(fn()=>$notTrusted->inspect($id,$nonce,$envelope,$now),
        'invalid_staff_identity_attestation');
    $q=$db->prepare("SELECT status,mfa_secret_ciphertext,mfa_enrolled_at
        FROM central_staff_users WHERE id=:id");
    $q->execute(['id'=>$id]);$state=$q->fetch(PDO::FETCH_ASSOC);
    attestationCheck($state['status']==='pending_mfa' &&
        $state['mfa_secret_ciphertext']===null &&
        $state['mfa_enrolled_at']===null,
        'valid and invalid evidence never changes staff status or credentials');
    $q=$db->prepare('SELECT count(*) FROM central_staff_role_grants WHERE staff_id=:id');
    $q->execute(['id'=>$id]);
    attestationCheck((int)$q->fetchColumn()===0,
        'identity evidence cannot grant a role');
} finally {
    putenv('CENTRAL_STAFF_IDENTITY_ATTESTATION_ENABLED=false');
}
echo "PASS: default-off synthetic external Ed25519 evidence validates exact pending account only, cannot activate or grant access\n";
