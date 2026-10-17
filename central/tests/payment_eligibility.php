<?php
declare(strict_types=1);

use Hafzatech\Central\PaidInvoiceEligibility;

require dirname(__DIR__).'/src/PaidInvoiceEligibility.php';

function paymentId(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function ensure(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL central invoice eligibility: '.$message);
}
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn||!str_contains($dsn,'hafzatech_central_test'))throw new RuntimeException('Dedicated central database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer=paymentId();$billing=paymentId();$officer=paymentId();
$account=paymentId();$plan=paymentId();$invoice=paymentId();
$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,'Synthetic subscription customer')")
    ->execute(['id'=>$customer]);
$s=$db->prepare("INSERT INTO central_staff_users
    (id,email,display_name,password_hash,mfa_enrolled_at,status)
    VALUES(:id,:email,:name,:hash,now(),'active')");
$s->execute(['id'=>$billing,'email'=>'payment.officer@example.test','name'=>'Independent Billing',
    'hash'=>password_hash('synthetic-test-only',PASSWORD_ARGON2ID)]);
$s->execute(['id'=>$officer,'email'=>'issuance.officer@example.test','name'=>'Separate License Officer',
    'hash'=>password_hash('synthetic-test-only',PASSWORD_ARGON2ID)]);
$g=$db->prepare("INSERT INTO central_staff_role_grants(id,staff_id,role,reason)
    VALUES(:id,:staff,:role,'Independent synthetic role registration')");
$g->execute(['id'=>paymentId(),'staff'=>$billing,'role'=>'billing_officer']);
$g->execute(['id'=>paymentId(),'staff'=>$officer,'role'=>'license_officer']);
$db->prepare("INSERT INTO central_vendor_receiving_accounts
    (id,provider_kind,display_label,country_code,receiving_currency,
     encrypted_instructions,status,verified_at,verified_by)
    VALUES(:id,'domestic_bank','Synthetic Vendor Bank','PK','PKR',
    decode(:hex,'hex'),'verified',now(),:staff)")
    ->execute(['id'=>$account,'hex'=>bin2hex(random_bytes(32)),'staff'=>$billing]);
$db->prepare("INSERT INTO central_customer_provider_assignments
    (id,customer_id,receiving_account_id,enabled)
    VALUES(:id,:customer,:account,true)")
    ->execute(['id'=>paymentId(),'customer'=>$customer,'account'=>$account]);
$db->prepare("INSERT INTO central_vendor_plan_versions
    (id,plan_code,version,term_months,currency,price_minor)
    VALUES(:id,'standard',1,12,'PKR',10000)")->execute(['id'=>$plan]);
$db->prepare("INSERT INTO central_vendor_invoices
    (id,customer_id,plan_version_id,invoice_number,currency,total_minor,
     receiving_account_id,encrypted_instructions_snapshot,status,issued_at)
    VALUES(:id,:customer,:plan,'SYNTHETIC-INVOICE-1','PKR',10000,
      :account,decode(:hex,'hex'),'issued',now())")
    ->execute(['id'=>$invoice,'customer'=>$customer,'plan'=>$plan,
        'account'=>$account,'hex'=>bin2hex(random_bytes(32))]);
$db->prepare("INSERT INTO central_vendor_payment_evidence
    (id,invoice_id,claimed_reference)
    VALUES(:id,:invoice,'Screenshot is not verified funds')")
    ->execute(['id'=>paymentId(),'invoice'=>$invoice]);
$guard=new PaidInvoiceEligibility($db);
ensure(!$guard->isEligible($invoice,$officer),'proof only does not qualify');
ensure(!$guard->isEligible($invoice,$billing),'billing officer is not issuer');
ensure(!$guard->isEligible($invoice,paymentId()),'unknown issuer denied');
$s=$db->prepare("INSERT INTO central_vendor_verified_settlements
    (id,invoice_id,receiving_account_id,currency,amount_minor,
     external_settlement_reference,verified_by,verified_at)
    VALUES(:id,:invoice,:account,'PKR',:amount,:reference,:billing,now())");
$s->execute(['id'=>paymentId(),'invoice'=>$invoice,'account'=>$account,
    'amount'=>4500,'reference'=>'synthetic-settlement-1','billing'=>$billing]);
ensure(!$guard->isEligible($invoice,$officer),'partial payment denied');
$s->execute(['id'=>paymentId(),'invoice'=>$invoice,'account'=>$account,
    'amount'=>5500,'reference'=>'synthetic-settlement-2','billing'=>$billing]);
ensure($guard->isEligible($invoice,$officer),'independent verified full payment accepted');
$db->prepare("UPDATE central_customer_provider_assignments SET enabled=false WHERE receiving_account_id=:id")
    ->execute(['id'=>$account]);
ensure(!$guard->isEligible($invoice,$officer),'inactive account assignment denies');
echo "PASS: independent central 6/12-month invoice payment eligibility, proof/partial/cross-role denial\n";
