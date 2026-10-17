<?php
declare(strict_types=1);

use Hafzatech\Central\DomainOwnershipVerifier;
use Hafzatech\Central\InstallationRegistry;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/DomainOwnershipVerifier.php';

function domainUuid(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function assertDomain(bool $ok,string $why): void {
    if(!$ok)throw new RuntimeException('FAIL isolated domain claim: '.$why);
}
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn||!str_contains($dsn,'hafzatech_central_test'))
    throw new RuntimeException('Dedicated central test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer=domainUuid();$enrollment=domainUuid();$proof=random_bytes(32);
$secretEnrollment=random_bytes(32);
$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,'DNS proof test client')")
    ->execute(['id'=>$customer]);
$db->prepare("INSERT INTO installation_enrollments
    (id,customer_id,token_sha256,approved_domain,expires_at)
    VALUES(:id,:customer,:token,'approved.example.test',now()+interval '1 hour')")
    ->execute(['id'=>$enrollment,'customer'=>$customer,'token'=>hash('sha256',$secretEnrollment)]);
$db->prepare("INSERT INTO central_installation_domain_proofs
    (enrollment_id,proof_sha256,expires_at)
    VALUES(:id,:hash,now()+interval '1 hour')")
    ->execute(['id'=>$enrollment,'hash'=>hash('sha256',$proof)]);
$wrong=new DomainOwnershipVerifier($db,static function(string $query): array{
    if($query!=='_hafzatech-verify.approved.example.test')
        throw new RuntimeException('Untrusted DNS lookup host');
    return ['hafzatech-verify='.InstallationRegistry::base64Url(random_bytes(32))];
});
assertDomain(!$wrong->verify($enrollment),'wrong DNS proof denied');
assertDomain((int)$db->query("SELECT count(*) FROM central_domain_verification_audit")->fetchColumn()===0,
    'wrong DNS record cannot mark ownership');
$correct=InstallationRegistry::base64Url($proof);
$verifier=new DomainOwnershipVerifier($db,
    static fn(string $query): array => ['hafzatech-verify='.$correct]);
assertDomain($verifier->verify($enrollment),'real TXT proof verified');
assertDomain($verifier->verify($enrollment),'successful DNS validation is idempotent');
assertDomain((int)$db->query("SELECT count(*) FROM central_domain_verification_audit")->fetchColumn()===1,
    'single auditable DNS proof');
$token=InstallationRegistry::base64Url($secretEnrollment);
$registry=new InstallationRegistry($db);
$challenge=$registry->challenge($enrollment,$token);
assertDomain(strlen($challenge['nonce'])===43,'only verified installation can begin enrollment');
assertDomain(!$verifier->verify(domainUuid()),'other enrollment not leaked');
echo "PASS: signed installation enrollment requires separate authorized DNS TXT ownership proof and audit\n";
