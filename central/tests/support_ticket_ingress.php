<?php
declare(strict_types=1);

use Hafzatech\Central\InstallationRegistry;
use Hafzatech\Central\InstallationStatusService;
use Hafzatech\Central\RegistrationError;
use Hafzatech\Central\SupportTicketIngress;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/InstallationStatusService.php';
require dirname(__DIR__).'/src/SupportTicketIngress.php';

function supportUuid(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function ensureSupport(bool $ok,string $desc): void {
    if(!$ok)throw new RuntimeException('FAIL central support ingress: '.$desc);
}
function supportDenied(callable $act,string $expected): void {
    try{$act();}catch(RegistrationError $e){
        ensureSupport($e->errorCode===$expected,'expected '.$expected.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('FAIL missing denial '.$expected);
}
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn||!str_contains($dsn,'hafzatech_central_test'))
    throw new RuntimeException('Refuse non-test database');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer=supportUuid();$other=supportUuid();$installation=supportUuid();$enrollment=supportUuid();
$pair=sodium_crypto_sign_keypair();$clientTicket=supportUuid();
$b64=static fn(string $s):string=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
$q=$db->prepare('INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)');
$q->execute(['id'=>$customer,'name'=>'Synthetic central support A']);
$q->execute(['id'=>$other,'name'=>'Synthetic central support B']);
$db->prepare("INSERT INTO installation_enrollments
    (id,customer_id,token_sha256,approved_domain,domain_verified_at,expires_at,claimed_at)
    VALUES(:id,:customer,:hash,'central-support.example.test',
    now(),now()+interval '1 day',now())")->execute([
    'id'=>$enrollment,'customer'=>$customer,'hash'=>hash('sha256',random_bytes(32))
]);
$db->prepare("INSERT INTO registered_installations
    (id,customer_id,enrollment_id,approved_domain,ed25519_public_key)
    VALUES(:id,:customer,:enrollment,'central-support.example.test',:pub)")
    ->execute(['id'=>$installation,'customer'=>$customer,'enrollment'=>$enrollment,
       'pub'=>$b64(sodium_crypto_sign_publickey($pair))]);
$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$seal=static function(string $raw)use($key):string {
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce.sodium_crypto_secretbox($raw,$nonce,$key);
};
$service=new SupportTicketIngress($db,new InstallationStatusService($db),$seal);
$build=static function(string $client, string $subject, string $body, ?string $customerOverride=null)
    use($b64,$pair,$customer,$installation):array{
    $c=$customerOverride??$customer;
    $nonce=$b64(random_bytes(32));
    $when=gmdate('Y-m-d\TH:i:s\Z');
    $status=implode("\n",['HAFZATECH-INSTALL-CHECK-V1',$installation,$c,
        $when,$nonce,'0']);
    $content=implode("\n",['HAFZATECH-SUPPORT-CREATE-V1',$installation,$c,
        $client,$nonce,hash('sha256',$subject),hash('sha256',$body)]);
    return [
        'api_version'=>'v1','installation_id'=>$installation,'customer_id'=>$c,
        'request_time'=>$when,'nonce'=>$nonce,'known_revision'=>0,
        'signature'=>$b64(sodium_crypto_sign_detached($status,
            sodium_crypto_sign_secretkey($pair))),
        'client_ticket_id'=>$client,'subject'=>$subject,'body'=>$body,
        'content_signature'=>$b64(sodium_crypto_sign_detached($content,
            sodium_crypto_sign_secretkey($pair))),
    ];
};
$subject='Please help with venue setup';
$body='Where do I configure the hall schedule?';
$request=$build($clientTicket,$subject,$body);
putenv('CENTRAL_SUPPORT_INGRESS_ENABLED=false');
supportDenied(fn()=>$service->receive($request),'support_ingress_not_enabled');
putenv('CENTRAL_SUPPORT_INGRESS_ENABLED=true');
try {
    $bad=$request;$bad['body']='Modified question';
    supportDenied(fn()=>$service->receive($bad),'invalid_support_proof');
    supportDenied(fn()=>$service->receive($build($clientTicket,$subject,$body,$other)),
        'invalid_support_proof');
    $first=$service->receive($request);
    ensureSupport($first['api_version']==='v1' &&
        str_starts_with($first['ticket_number'],'HAF-'),'stable server ticket number');
    ensureSupport($first['status']==='new','staff ticket begins new');
    supportDenied(fn()=>$service->receive($request),'replayed_check');
    $again=$service->receive($build($clientTicket,$subject,$body));
    ensureSupport($again===$first,'retries return same vendor ticket');
    supportDenied(fn()=>$service->receive($build($clientTicket,$subject,'Changed contents')),
        'support_idempotency_conflict');
    $q=$db->prepare("SELECT encode(subject_ciphertext,'hex') FROM vendor_support_tickets
        WHERE customer_id=:customer AND client_ticket_id=:client");
    $q->execute(['customer'=>$customer,'client'=>$clientTicket]);
    $cipher=$q->fetchColumn();
    ensureSupport(is_string($cipher) && !str_contains($cipher,bin2hex($subject)),
        'subject is encrypted at central rest');
    $q=$db->prepare('SELECT count(*) FROM vendor_support_tickets WHERE customer_id=:customer');
    $q->execute(['customer'=>$customer]);
    ensureSupport((int)$q->fetchColumn()===1,'no duplicate ticket');
    $q=$db->prepare('SELECT count(*) FROM vendor_support_messages WHERE ticket_id=:ticket AND customer_id=:customer');
    $q->execute(['ticket'=>$first['ticket_id'],'customer'=>$customer]);
    ensureSupport((int)$q->fetchColumn()===1,'one encrypted message');
    echo "PASS: central signed support ingress is tenant-scoped, idempotent, encrypted, replay-safe and default OFF\n";
} finally {
    putenv('CENTRAL_SUPPORT_INGRESS_ENABLED=false');
}
