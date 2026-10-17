<?php
declare(strict_types=1);

use Hafzatech\Central\VendorReceivingDirectory;

require dirname(__DIR__).'/src/VendorReceivingDirectory.php';

function guid(): string {
    $bytes=random_bytes(16);
    $bytes[6]=chr((ord($bytes[6])&15)|64);
    $bytes[8]=chr((ord($bytes[8])&63)|128);
    $hex=bin2hex($bytes);
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}
function requireCondition(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL vendor provider: '.$message);
}
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn||!str_contains($dsn,'hafzatech_central_test'))throw new RuntimeException('Dedicated central test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customerA=guid();$customerB=guid();$staff=guid();$bank=guid();$wallet=guid();$paypal=guid();
$c=$db->prepare('INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)');
$c->execute(['id'=>$customerA,'name'=>'Vendor client A']);
$c->execute(['id'=>$customerB,'name'=>'Vendor client B']);
$db->prepare("INSERT INTO central_staff_users
    (id,email,display_name,password_hash,mfa_enrolled_at,status)
    VALUES(:id,'provider.audit@example.test','Synthetic Billing Officer',:hash,now(),'active')")
    ->execute(['id'=>$staff,'hash'=>password_hash('synthetic-password-only',PASSWORD_ARGON2ID)]);
$db->prepare("INSERT INTO central_staff_role_grants(id,staff_id,role,reason)
    VALUES(:id,:staff,'billing_officer','Synthetic provider authorization')")
    ->execute(['id'=>guid(),'staff'=>$staff]);
$p=$db->prepare("INSERT INTO central_vendor_receiving_accounts
    (id,provider_kind,display_label,country_code,receiving_currency,encrypted_instructions,status,verified_at,verified_by)
    VALUES(:id,:provider,:label,'PK','PKR',decode(:secret, 'hex'),:status,:verified_at,:verified_by)");
$p->execute(['id'=>$bank,'provider'=>'domestic_bank','label'=>'HAFZATECH PK Bank',
    'secret'=>bin2hex(random_bytes(32)),'status'=>'verified','verified_at'=>gmdate('Y-m-d H:i:sP'),'verified_by'=>$staff]);
$p->execute(['id'=>$wallet,'provider'=>'easypaisa','label'=>'HAFZATECH Wallet',
    'secret'=>bin2hex(random_bytes(32)),'status'=>'draft','verified_at'=>null,'verified_by'=>null]);
$p->execute(['id'=>$paypal,'provider'=>'paypal','label'=>'Future PayPal',
    'secret'=>bin2hex(random_bytes(32)),'status'=>'draft','verified_at'=>null,'verified_by'=>null]);
$a=$db->prepare("INSERT INTO central_customer_provider_assignments
    (id,customer_id,receiving_account_id,enabled) VALUES(:id,:customer,:account,true)");
foreach ([$bank,$wallet,$paypal] as $account) {
    $a->execute(['id'=>guid(),'customer'=>$customerA,'account'=>$account]);
}
$directory=new VendorReceivingDirectory($db);
$available=$directory->allowedForCustomer($customerA,'PKR');
requireCondition(count($available)===1, 'verified assigned accounts only');
requireCondition($available[0]['id']===$bank && $available[0]['provider']==='domestic_bank',
    'provider identity and country match');
requireCondition(!array_key_exists('encrypted_instructions',$available[0]),
    'do not leak receiving secret in customer account selector');
requireCondition($directory->allowedForCustomer($customerB,'PKR')===[],
    'cross-customer account mapping denied');
requireCondition($directory->allowedForCustomer($customerA,'USD')===[],
    'currency mismatch denied');
$db->prepare("UPDATE central_staff_users SET status='suspended' WHERE id=:id")
    ->execute(['id'=>$staff]);
requireCondition($directory->allowedForCustomer($customerA,'PKR')===[],
    'suspended verifier is not eligible');
$db->prepare("UPDATE central_staff_users SET status='active' WHERE id=:id")
    ->execute(['id'=>$staff]);
$db->prepare('UPDATE central_customer_provider_assignments SET enabled=false
    WHERE receiving_account_id=:id')->execute(['id'=>$bank]);
requireCondition($directory->allowedForCustomer($customerA,'PKR')===[],
    'revoked assignment stops visibility');
echo "PASS: HAFZATECH vendor receiving assignment isolation, draft PayPal denial and secret-redacted currency selection\n";
