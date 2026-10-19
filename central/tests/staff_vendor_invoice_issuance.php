<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\CentralStaffSessionService;
use Hafzatech\Central\CentralStaffSessionAuthorization;
use Hafzatech\Central\CentralStaffVendorInvoiceIssuer;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach([
    'StaffRolePermissions','CentralStaffAccess','CentralStaffPasswordVerifier',
    'CentralStaffTotpVerifier','CentralStaffMfaChallenge',
    'CentralStaffCredentialVerifier','CentralStaffLoginRateLimiter',
    'CentralStaffSessionService','CentralStaffSessionAuthorization',
    'CentralStaffVendorInvoiceIssuer'
] as $class)require dirname(__DIR__).'/src/'.$class.'.php';

function invoiceIssueAssert(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('Central invoice issuance: '.$why);
}
function invoiceIssueDeny(callable $f,string $code):void {
    try {$f();}catch(RegistrationError $e){
        invoiceIssueAssert($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Expected invoice issuance denial '.$code);
}
function invoiceIssueUuid():string {
    $b=random_bytes(16);
    $b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.
        substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function invoiceIssueTotp(string $seed):string {
    $n=intdiv(time(),30);
    $hash=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),$seed,true);
    $offset=ord($hash[19])&15;
    $v=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;
    return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);
}

$dsn=getenv('CENTRAL_DB_DSN');
invoiceIssueAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'isolated PostgreSQL required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer=invoiceIssueUuid();$otherCustomer=invoiceIssueUuid();
$billing=invoiceIssueUuid();$viewer=invoiceIssueUuid();
$bank=invoiceIssueUuid();$bankAssignment=invoiceIssueUuid();
$planSix=invoiceIssueUuid();$planTwelve=invoiceIssueUuid();$planForeignCurrency=invoiceIssueUuid();
$request=invoiceIssueUuid();
$seed='12345678901234567890';$password='synthetic-vendor-invoice-password';
$customers=$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)");
$customers->execute(['id'=>$customer,'name'=>'Invoice Issuance Test Venue']);
$customers->execute(['id'=>$otherCustomer,'name'=>'Foreign Invoice Venue']);
$staff=$db->prepare("INSERT INTO central_staff_users
    (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
    VALUES(:id,:email,'Synthetic vendor invoice officer',:hash,
    decode(:secret,'hex'),now(),'active')");
foreach([[$billing,'billing'],[$viewer,'support']] as [$id,$label]) {
    $staff->execute(['id'=>$id,'email'=>'invoice-'.$label.'@example.test',
        'hash'=>password_hash($password,PASSWORD_ARGON2ID),
        'secret'=>bin2hex($seed)]);
}
$roles=$db->prepare("INSERT INTO central_staff_role_grants
    (id,staff_id,role,customer_scope_id,reason)
    VALUES(:id,:staff,:role,:scope,'Synthetic permission regression')");
$roles->execute(['id'=>invoiceIssueUuid(),'staff'=>$billing,
    'role'=>'billing_officer','scope'=>$customer]);
$roles->execute(['id'=>invoiceIssueUuid(),'staff'=>$viewer,
    'role'=>'support_agent','scope'=>null]);
$bankCipher=random_bytes(32);
$db->prepare("INSERT INTO central_vendor_receiving_accounts
    (id,provider_kind,display_label,country_code,receiving_currency,
    encrypted_instructions,status,verified_at,verified_by)
    VALUES(:id,'domestic_bank','Synthetic permitted vendor bank','US','USD',
    decode(:cipher,'hex'),'verified',now(),:billing)")
    ->execute(['id'=>$bank,'cipher'=>bin2hex($bankCipher),'billing'=>$billing]);
$db->prepare("INSERT INTO central_customer_provider_assignments
    (id,customer_id,receiving_account_id,enabled)
    VALUES(:id,:customer,:bank,true)")
    ->execute(['id'=>$bankAssignment,'customer'=>$customer,'bank'=>$bank]);
$insertPlan=$db->prepare("INSERT INTO central_vendor_plan_versions
    (id,plan_code,version,term_months,currency,price_minor,entitlements_snapshot)
    VALUES(:id,:code,1,:term,:currency,:price,:benefits)");
foreach([
    [$planSix,'issuer_test_6',6,'USD',12500],
    [$planTwelve,'issuer_test_12',12,'USD',21000],
    [$planForeignCurrency,'issuer_test_pkr',6,'PKR',18000]
] as [$id,$code,$term,$currency,$price]) {
    $insertPlan->execute(['id'=>$id,'code'=>$code,'term'=>$term,
        'currency'=>$currency,'price'=>$price,'benefits'=>json_encode([
            'max_halls'=>2,'max_staff'=>12,'features'=>['booking'=>true],
        ],JSON_THROW_ON_ERROR)]);
}
$sessions=new CentralStaffSessionService($db,
    new Hafzatech\Central\CentralStaffCredentialVerifier(
        new Hafzatech\Central\CentralStaffPasswordVerifier($db),
        new Hafzatech\Central\CentralStaffMfaChallenge(
            $db,static fn(string $cipher):string=>$cipher)),
    new Hafzatech\Central\CentralStaffLoginRateLimiter($db,str_repeat('I',32)));
$token=invoiceIssueTotp($seed);
$billBearer=$sessions->login('invoice-billing@example.test',$password,$token,'192.0.2.201');
$viewerBearer=$sessions->login('invoice-support@example.test',$password,$token,'192.0.2.202');
invoiceIssueAssert(is_string($billBearer)&&is_string($viewerBearer),
    'MFA staff sessions must be real synthetic sessions');
$auth=new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db));
$off=new CentralStaffVendorInvoiceIssuer($db,$sessions,$auth);
$on=new CentralStaffVendorInvoiceIssuer($db,$sessions,$auth,true);

putenv('CENTRAL_VENDOR_INVOICE_ISSUANCE_ENABLED=false');
invoiceIssueDeny(fn()=>$on->issue($billBearer,$customer,$planSix,$bank,$request),
    'vendor_invoice_issuance_not_enabled');
putenv('CENTRAL_VENDOR_INVOICE_ISSUANCE_ENABLED=true');
try {
    invoiceIssueDeny(fn()=>$off->issue($billBearer,$customer,$planSix,$bank,$request),
        'vendor_invoice_issuance_not_enabled');
    invoiceIssueDeny(fn()=>$on->issue($viewerBearer,$customer,$planSix,$bank,$request),
        'billing_invoice_issuer_required');
    invoiceIssueDeny(fn()=>$on->issue(str_repeat('0',64),$customer,$planSix,$bank,$request),
        'billing_invoice_issuer_required');
    invoiceIssueDeny(fn()=>$on->issue($billBearer,$otherCustomer,$planSix,$bank,$request),
        'billing_invoice_issuer_required');
    invoiceIssueDeny(fn()=>$on->issue($billBearer,$customer,$planSix,$bank,'forged'),
        'invalid_vendor_invoice_request');
    invoiceIssueDeny(fn()=>$on->issue($billBearer,$customer,$planForeignCurrency,
        $bank,invoiceIssueUuid()),'vendor_invoice_receiver_unavailable');
    $db->prepare("UPDATE central_customer_provider_assignments SET enabled=false
        WHERE id=:id")->execute(['id'=>$bankAssignment]);
    invoiceIssueDeny(fn()=>$on->issue($billBearer,$customer,$planSix,
        $bank,invoiceIssueUuid()),'vendor_invoice_receiver_unavailable');
    $db->prepare("UPDATE central_customer_provider_assignments SET enabled=true
        WHERE id=:id")->execute(['id'=>$bankAssignment]);

    $first=$on->issue($billBearer,$customer,$planSix,$bank,$request);
    invoiceIssueAssert($first['currency']==='USD'&&$first['total_minor']==='12500'
        &&$first['term_months']===6&&$first['status']==='issued'
        &&str_starts_with($first['invoice_number'],'HTZ-'),
        'invoice price, currency, term, number derived from trusted versioned plan');
    invoiceIssueAssert($on->issue($billBearer,$customer,$planSix,$bank,$request)===$first,
        'exact same issued request is idempotent');
    invoiceIssueDeny(fn()=>$on->issue($billBearer,$customer,$planTwelve,$bank,$request),
        'vendor_invoice_request_conflict');
    $q=$db->prepare("SELECT i.customer_id,i.plan_version_id,i.currency,i.total_minor,
        i.status,i.invoice_number,encode(i.encrypted_instructions_snapshot,'hex') AS encrypted,
        e.issued_by_staff_id,e.request_sha256
        FROM central_vendor_invoices i
        JOIN central_vendor_invoice_issue_events e ON e.invoice_id=i.id
        WHERE i.id=:id");
    $q->execute(['id'=>$first['invoice_id']]);
    $record=$q->fetch(PDO::FETCH_ASSOC);
    invoiceIssueAssert($record['customer_id']===$customer
        &&$record['plan_version_id']===$planSix
        &&$record['currency']==='USD'
        &&(int)$record['total_minor']===12500
        &&$record['issued_by_staff_id']===$billing
        &&$record['encrypted']===bin2hex($bankCipher)
        &&strlen($record['request_sha256'])===64,
        'customer, verified provider ciphertext and MFA issuer durably bound');
    invoiceIssueAssert(!str_contains(json_encode($first),bin2hex($bankCipher)),
        'no bank secrets returned in invoice issuance response');
    try {
        $db->prepare("DELETE FROM central_vendor_invoice_issue_events
            WHERE invoice_id=:invoice")->execute(['invoice'=>$first['invoice_id']]);
        throw new RuntimeException('Unexpected mutable invoice issuance evidence');
    } catch(PDOException $e) {
        invoiceIssueAssert($e->getCode()==='P0001','immutable audit trigger');
    }
    // Other independently tested vendor invoices may already have settled
    // in this shared CI database. Assert NO movement for this exact invoice.
    $settlementCount=$db->prepare(
        'SELECT count(*) FROM central_vendor_verified_settlements WHERE invoice_id=:id');
    $settlementCount->execute(['id'=>$first['invoice_id']]);
    $count=(int)$settlementCount->fetchColumn();
    $revisionCount=$db->prepare(
        'SELECT count(*) FROM central_paid_license_revisions WHERE invoice_id=:id');
    $revisionCount->execute(['id'=>$first['invoice_id']]);
    $revisions=(int)$revisionCount->fetchColumn();
    invoiceIssueAssert($count===0 && $revisions===0,
        'invoice never verifies money or creates signed entitlements');
    $db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
        WHERE staff_id=:staff")->execute(['staff'=>$billing]);
    invoiceIssueDeny(fn()=>$on->issue($billBearer,$customer,$planTwelve,
        $bank,invoiceIssueUuid()),'billing_invoice_issuer_required');
} finally {
    putenv('CENTRAL_VENDOR_INVOICE_ISSUANCE_ENABLED=false');
}
echo "PASS: gated MFA vendor invoice, locked price/account, idempotent issuance, immutable audit and no false settlement\n";
