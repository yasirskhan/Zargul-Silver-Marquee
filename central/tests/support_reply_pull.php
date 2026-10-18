<?php
declare(strict_types=1);

use Hafzatech\Central\InstallationRegistry;
use Hafzatech\Central\InstallationStatusService;
use Hafzatech\Central\RegistrationError;
use Hafzatech\Central\SupportReplyPullService;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/InstallationStatusService.php';
require dirname(__DIR__).'/src/SupportReplyPullService.php';

function replyPollUuid():string{
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function replyPollAssert(bool $yes,string $msg):void {
    if(!$yes)throw new RuntimeException('FAIL support reply poll: '.$msg);
}
function replyPollDenied(callable $fn,string $code):void {
    try{$fn();}catch(RegistrationError $e){
        replyPollAssert($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('FAIL expected denial '.$code);
}
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn||!str_contains($dsn,'hafzatech_central_test'))
    throw new RuntimeException('Dedicated central test DB only');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer=replyPollUuid();$other=replyPollUuid();$install=replyPollUuid();
$enrollment=replyPollUuid();$ticket=replyPollUuid();$clientTicket=replyPollUuid();
$staff=replyPollUuid();$message=replyPollUuid();$sourceMessage=replyPollUuid();
$pair=sodium_crypto_sign_keypair();
$b64=static fn(string $str):string=>rtrim(strtr(base64_encode($str),'+/','-_'),'=');
$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,'Synthetic reply polling client')")
    ->execute(['id'=>$customer]);
$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,'Other reply poll client')")
    ->execute(['id'=>$other]);
$db->prepare("INSERT INTO installation_enrollments
    (id,customer_id,token_sha256,approved_domain,domain_verified_at,expires_at,claimed_at)
    VALUES(:id,:customer,:token,'reply-poll.example.test',now(),now()+interval '1 day',now())")
    ->execute(['id'=>$enrollment,'customer'=>$customer,'token'=>hash('sha256',random_bytes(32))]);
$db->prepare("INSERT INTO registered_installations
    (id,customer_id,enrollment_id,approved_domain,ed25519_public_key)
    VALUES(:id,:customer,:enrollment,'reply-poll.example.test',:pub)")
    ->execute(['id'=>$install,'customer'=>$customer,'enrollment'=>$enrollment,
        'pub'=>$b64(sodium_crypto_sign_publickey($pair))]);
$db->prepare("INSERT INTO central_staff_users
    (id,email,display_name,password_hash,mfa_enrolled_at,status)
    VALUES(:id,'poll.staff@example.test','Support Poll Agent',:hash,now(),'active')")
    ->execute(['id'=>$staff,'hash'=>password_hash('test-only',PASSWORD_ARGON2ID)]);
$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$seal=static function(string $raw)use($key):string{
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce.sodium_crypto_secretbox($raw,$nonce,$key);
};
$open=static function(string $cipher)use($key):string{
    $nonce=substr($cipher,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $value=sodium_crypto_secretbox_open(substr($cipher,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$key);
    if(!is_string($value))throw new RuntimeException('Invalid protected reply ciphertext');
    return $value;
};
$db->prepare("INSERT INTO vendor_support_tickets
    (id,customer_id,installation_id,client_ticket_id,subject_ciphertext,content_sha256)
    VALUES(:id,:customer,:installation,:client,decode(:cipher,'hex'),:digest)")
    ->execute(['id'=>$ticket,'customer'=>$customer,'installation'=>$install,
        'client'=>$clientTicket,'cipher'=>bin2hex($seal('Synthetic support title')),
        'digest'=>hash('sha256','Synthetic support title')]);
$db->prepare("INSERT INTO vendor_support_messages
    (id,ticket_id,customer_id,source_message_id,direction,
     author_staff_id,body_ciphertext,content_sha256)
    VALUES(:id,:ticket,:customer,:source,'staff',:staff,decode(:cipher,'hex'),:digest)")
    ->execute(['id'=>$message,'ticket'=>$ticket,'customer'=>$customer,
        'source'=>$sourceMessage,'staff'=>$staff,
        'cipher'=>bin2hex($seal('Approved support answer.')),
        'digest'=>hash('sha256','Approved support answer.')]);
$db->prepare("INSERT INTO vendor_support_reply_audit
    (ticket_id,customer_id,staff_id,message_id,action)
    VALUES(:ticket,:customer,:staff,:message,'support.agent_reply')")
    ->execute(['ticket'=>$ticket,'customer'=>$customer,'staff'=>$staff,'message'=>$message]);
$service=new SupportReplyPullService($db,new InstallationStatusService($db),$open);
$sign=static function(int $cursor,int $limit,string $customerId)use($pair,$install,$b64):array{
    $when=gmdate('Y-m-d\TH:i:s\Z');$nonce=$b64(random_bytes(32));
    $check=implode("\n",['HAFZATECH-INSTALL-CHECK-V1',
        $install,$customerId,$when,$nonce,'0']);
    $cursorMessage=implode("\n",['HAFZATECH-SUPPORT-CHANGES-V1',
        $install,$customerId,(string)$cursor,(string)$limit,$nonce]);
    return [
        'installation_id'=>$install,'customer_id'=>$customerId,
        'request_time'=>$when,'nonce'=>$nonce,'known_revision'=>0,
        'signature'=>$b64(sodium_crypto_sign_detached($check,
            sodium_crypto_sign_secretkey($pair))),
        'cursor'=>$cursor,'limit'=>$limit,
        'cursor_signature'=>$b64(sodium_crypto_sign_detached($cursorMessage,
            sodium_crypto_sign_secretkey($pair))),
    ];
};
$req=$sign(0,10,$customer);
putenv('CENTRAL_SUPPORT_PULL_ENABLED=false');
replyPollDenied(fn()=>$service->pull($req),'support_pull_not_enabled');
putenv('CENTRAL_SUPPORT_PULL_ENABLED=true');
try{
    $tamper=$req;$tamper['cursor']=500;
    replyPollDenied(fn()=>$service->pull($tamper),'invalid_support_cursor_proof');
    replyPollDenied(fn()=>$service->pull($sign(0,10,$other)),'unauthorized_support_installation');
    $first=$service->pull($req);
    replyPollAssert(count($first['messages'])===1,'exactly one company-scoped reply');
    replyPollAssert($first['messages'][0]['body']==='Approved support answer.',
        'protected reply decrypts after signed customer identity');
    replyPollAssert($first['messages'][0]['client_ticket_id']===$clientTicket,
        'reply bound to client ticket');
    replyPollAssert($first['next_cursor']===$first['messages'][0]['event_id'],
        'cursor follows server audit event ID');
    replyPollDenied(fn()=>$service->pull($req),'replayed_check');
    $next=$service->pull($sign($first['next_cursor'],10,$customer));
    replyPollAssert($next['messages']===[] && $next['next_cursor']===$first['next_cursor'],
        'no re-delivery after last cursor');
    echo "PASS: signed support reply polling, customer scope, cursor, replay and protected body\n";
}finally{
    putenv('CENTRAL_SUPPORT_PULL_ENABLED=false');
}
