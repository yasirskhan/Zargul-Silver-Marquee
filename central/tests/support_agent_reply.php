<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\RegistrationError;
use Hafzatech\Central\StaffRolePermissions;
use Hafzatech\Central\SupportAgentReplyService;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/StaffRolePermissions.php';
require dirname(__DIR__).'/src/CentralStaffAccess.php';
require dirname(__DIR__).'/src/SupportAgentReplyService.php';

function replyUuid(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function replyAssert(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('FAIL central staff support reply: '.$why);
}
function replyDeny(callable $fn,string $code):void{
    try{$fn();}catch(RegistrationError $e){
        replyAssert($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('FAIL missing denial '.$code);
}
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn||!str_contains($dsn,'hafzatech_central_test'))throw new RuntimeException('Dedicated support test database only');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer=replyUuid();$foreign=replyUuid();$staff=replyUuid();$staff2=replyUuid();
$enrollmentA=replyUuid();$enrollmentB=replyUuid();
$installA=replyUuid();$installB=replyUuid();
$ticketA=replyUuid();$ticketB=replyUuid();$messageId=replyUuid();
$q=$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)");
$q->execute(['id'=>$customer,'name'=>'Central staff support customer A']);
$q->execute(['id'=>$foreign,'name'=>'Central staff support customer B']);
$enroll=$db->prepare(
    "INSERT INTO installation_enrollments
     (id,customer_id,token_sha256,approved_domain,domain_verified_at,expires_at,claimed_at)
     VALUES(:id,:customer,:token,:domain,now(),now()+interval '1 hour',now())"
);
$enroll->execute(['id'=>$enrollmentA,'customer'=>$customer,'token'=>hash('sha256',random_bytes(32)),
    'domain'=>'staffreplya.example.test']);
$enroll->execute(['id'=>$enrollmentB,'customer'=>$foreign,'token'=>hash('sha256',random_bytes(32)),
    'domain'=>'staffreplyb.example.test']);
$install=$db->prepare(
    "INSERT INTO registered_installations
     (id,customer_id,enrollment_id,approved_domain,ed25519_public_key)
     VALUES(:id,:customer,:enrollment,:domain,:public)"
);
$encode=static fn(string $raw)=>rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
$install->execute(['id'=>$installA,'customer'=>$customer,'enrollment'=>$enrollmentA,
    'domain'=>'staffreplya.example.test','public'=>$encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
$install->execute(['id'=>$installB,'customer'=>$foreign,'enrollment'=>$enrollmentB,
    'domain'=>'staffreplyb.example.test','public'=>$encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
$tickets=$db->prepare(
    "INSERT INTO vendor_support_tickets
     (id,customer_id,installation_id,client_ticket_id,subject_ciphertext,content_sha256)
     VALUES(:id,:customer,:installation,:client,decode(:subject,'hex'),:digest)"
);
$tickets->execute(['id'=>$ticketA,'customer'=>$customer,'installation'=>$installA,
    'client'=>replyUuid(),'subject'=>bin2hex(random_bytes(32)),
    'digest'=>hash('sha256','synthetic ticket A')]);
$tickets->execute(['id'=>$ticketB,'customer'=>$foreign,'installation'=>$installB,
    'client'=>replyUuid(),'subject'=>bin2hex(random_bytes(32)),
    'digest'=>hash('sha256','synthetic ticket B')]);
$staffInsert=$db->prepare(
    "INSERT INTO central_staff_users
     (id,email,display_name,password_hash,mfa_enrolled_at,status)
     VALUES(:id,:email,:name,:pass,now(),'active')"
);
$staffInsert->execute(['id'=>$staff,'email'=>'scoped.support@example.test',
    'name'=>'Scoped Support Agent','pass'=>password_hash('synthetic-only',PASSWORD_ARGON2ID)]);
$staffInsert->execute(['id'=>$staff2,'email'=>'another.support@example.test',
    'name'=>'Unassigned Support Agent','pass'=>password_hash('synthetic-only',PASSWORD_ARGON2ID)]);
$db->prepare(
    "INSERT INTO central_staff_role_grants
     (id,staff_id,role,customer_scope_id,reason)
     VALUES(:id,:staff,'support_agent',:customer,'Test-only scoped support review')"
)->execute(['id'=>replyUuid(),'staff'=>$staff,'customer'=>$customer]);
$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$seal=static function(string $value)use($key):string{
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce.sodium_crypto_secretbox($value,$nonce,$key);
};
$service=new SupportAgentReplyService($db,new CentralStaffAccess($db),$seal);
putenv('CENTRAL_SUPPORT_STAFF_REPLY_ENABLED=false');
replyDeny(fn()=>$service->reply($ticketA,$messageId,$staff,'Please review the settings guide.',true),
    'support_reply_disabled');
putenv('CENTRAL_SUPPORT_STAFF_REPLY_ENABLED=true');
try {
    replyDeny(fn()=>$service->reply($ticketA,$messageId,$staff,'Please review the settings guide.',false),
        'unauthorized_support_reply');
    replyDeny(fn()=>$service->reply($ticketB,$messageId,$staff,'Wrong company message',true),
        'support_scope_denied');
    replyDeny(fn()=>$service->reply($ticketA,$messageId,$staff2,'Other agent message',true),
        'support_scope_denied');
    $body='Please review the published settings guide.';
    $result=$service->reply($ticketA,$messageId,$staff,$body,true);
    replyAssert($result['status']==='waiting_for_client','reply waits for customer');
    replyAssert($service->reply($ticketA,$messageId,$staff,$body,true)===$result,
        'same message request idempotent');
    replyDeny(fn()=>$service->reply($ticketA,$messageId,$staff,'Altered duplicate message',true),
        'support_reply_idempotency_conflict');
    $q=$db->prepare("SELECT count(*) FROM vendor_support_messages
        WHERE ticket_id=:ticket AND customer_id=:customer AND direction='staff'");
    $q->execute(['ticket'=>$ticketA,'customer'=>$customer]);
    replyAssert((int)$q->fetchColumn()===1,'exactly one staff reply');
    $q=$db->prepare('SELECT count(*) FROM vendor_support_reply_audit WHERE ticket_id=:ticket');
    $q->execute(['ticket'=>$ticketA]);
    replyAssert((int)$q->fetchColumn()===1,'exactly one immutable reply audit');
    try {
        $db->prepare('DELETE FROM vendor_support_reply_audit WHERE ticket_id=:ticket')
            ->execute(['ticket'=>$ticketA]);
        throw new RuntimeException('FAIL support reply audit was deletable');
    } catch(PDOException $expected) {}
    try {
        $db->prepare('DELETE FROM vendor_support_messages WHERE ticket_id=:ticket')
            ->execute(['ticket'=>$ticketA]);
        throw new RuntimeException('FAIL support message was deletable');
    } catch(PDOException $expected) {}

    $db->prepare("UPDATE central_staff_users SET status='suspended' WHERE id=:id")
        ->execute(['id'=>$staff]);
    replyDeny(fn()=>$service->reply($ticketA,replyUuid(),$staff,'Suspended actor reply',true),
        'support_scope_denied');
    echo "PASS: vendor support staff MFA, per-customer permission, encrypted audited reply and idempotency\n";
} finally {
    putenv('CENTRAL_SUPPORT_STAFF_REPLY_ENABLED=false');
}
