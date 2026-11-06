<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffPortalOperatorWiring;
use Hafzatech\Central\RegistrationError;

foreach ([
    'InstallationRegistry','StaffRolePermissions','CentralStaffAccess','CentralStaffPasswordVerifier',
    'CentralStaffTotpVerifier','CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
    'CentralStaffLoginRateLimiter','CentralStaffSessionService','CentralStaffSessionAuthorization',
    'CentralStaffCsrfTokenService','CentralStaffHttpPolicy','CentralStaffProtectedHttpBoundary',
    'CentralStaffPaymentEvidenceQueue','CentralStaffPaymentEvidenceReviewService',
    'CentralStaffInvoicePaymentOverview','CentralStaffPaymentEvidenceHttpAdapter',
    'CentralStaffBillingDashboardRenderer','CentralStaffCustomerLookup','CentralStaffPortalDashboard',
    'CentralStaffSupportTicketQueue','CentralStaffSupportQueueHtmlRenderer','CentralStaffSessionAuditReader',
    'CentralStaffAccountActionReader','CentralStaffProvisionAuditReader','CentralStaffPortalAuditHistoryPage',
    'CentralStaffLicenseSigningQueue','CentralStaffLicenseQueueHtmlRenderer','CentralStaffAccountDirectory',
    'CentralStaffRoleGrantDirectory','CentralStaffProvisioningService','CentralStaffActivationReadiness',
    'SupportAgentReplyService','CentralStaffSessionSupportReply','CentralStaffOwnPasswordChange',
    'CentralStaffPortalOwnPassword','CentralStaffPortalTeamDashboard','CentralStaffPortalAuditorDashboard',
    'CentralStaffRoleReviewQueue','CentralStaffRoleRequestService','CentralStaffRoleDecisionService',
    'CentralStaffVendorInvoiceIssuer','CentralStaffVendorInvoiceReceiptRenderer',
    'CentralStaffVendorInvoiceDirectory','CentralStaffVendorInvoiceDirectoryRenderer',
    'CentralStaffVendorPlanPublisher','CentralStaffVendorPlanCatalogRenderer','CentralStaffVendorPlanCatalogReader',
    'CentralStaffPortalHome','CentralStaffCustomerDirectory','CentralStaffCustomerDirectoryRenderer',
    'DesignerCreditPolicy','CentralStaffDesignerCreditService','CentralStaffDesignerCreditRenderer',
    'CentralStaffInstallationDirectory','CentralStaffPortalHttpRouter','CentralStaffPortalPrivateResponse',
    'CentralStaffPortalFormRequest','CentralStaffPortalOperatorKernel','CentralStaffPortalResponseEmitter',
    'CentralStaffPortalOperatorApp','CentralStaffPortalOperatorWiring',
] as $class) require dirname(__DIR__).'/src/'.$class.'.php';

function dcAssert(bool $ok,string $why):void { if(!$ok) throw new RuntimeException('Designer credit route: '.$why); }
function dcId():string {$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
function dcOtp(string $secret):string {$step=intdiv(time(),30);$bits=pack('N2',intdiv($step,4294967296),$step%4294967296);$mac=hash_hmac('sha1',$bits,$secret,true);$at=ord($mac[19])&15;$n=unpack('N',substr($mac,$at,4))[1]&0x7fffffff;return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);}
function dcDeny(callable $fn):void {
    try { $fn(); } catch (RegistrationError $e) { return; }
    throw new RuntimeException('Designer credit route: expected denial');
}
function dcLogin($app,array $server,string $email,string $password,string $secret): array {
    $headers=[];$cookies=[];$bodies=[];
    $app->serve(array_merge($server,['REQUEST_URI'=>'/staff/session/login']),[],
        http_build_query(['email'=>$email,'password'=>$password,'totp'=>dcOtp($secret)]));
    dcAssert(count($cookies)===1&&strlen($cookies[0][1])===64,'login failed for '.$email);
    preg_match('/name="staff_csrf" value="([a-f0-9]{64})"/',end($bodies),$m);
    dcAssert(isset($m[1]),'csrf missing');
    return [['__Host-HafzatechStaff'=>$cookies[0][1]],$m[1]];
}

$dsn=getenv('CENTRAL_DB_DSN');
dcAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),'isolated DB required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$companyA=dcId();$companyB=dcId();$licenseA=dcId();$licenseB=dcId();
foreach ([[$companyA,'credit-a'],[$companyB,'credit-b']] as [$customer,$suffix]) {
    $enroll=dcId();$install=dcId();$license=$suffix==='credit-a'?$licenseA:$licenseB;
    $domain=$suffix.'.example.test';
    $db->prepare("INSERT INTO vendor_customers(id,legal_name,status) VALUES(:id,:name,'active')")
        ->execute(['id'=>$customer,'name'=>'Credit Venue '.$suffix]);
    $db->prepare("INSERT INTO installation_enrollments(id,customer_id,token_sha256,approved_domain,expires_at)
        VALUES(:id,:customer,:token,:domain,now()+interval '1 day')")
        ->execute(['id'=>$enroll,'customer'=>$customer,'token'=>hash('sha256',$enroll),'domain'=>$domain]);
    $db->prepare("INSERT INTO registered_installations(id,customer_id,enrollment_id,approved_domain,ed25519_public_key,status)
        VALUES(:id,:customer,:enrollment,:domain,:key,'active')")
        ->execute(['id'=>$install,'customer'=>$customer,'enrollment'=>$enroll,'domain'=>$domain,
            'key'=>rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=')]);
    $db->prepare("INSERT INTO central_licenses(id,customer_id,installation_id,approved_domain,current_revision)
        VALUES(:id,:customer,:install,:domain,0)")
        ->execute(['id'=>$license,'customer'=>$customer,'install'=>$install,'domain'=>$domain]);
}

function dcStaff(PDO $db,string $role,string $customer): array {
    $id=dcId();$secret='credit-'.bin2hex(random_bytes(8));$email='credit-'.bin2hex(random_bytes(4)).'@example.test';
    $db->prepare("INSERT INTO central_staff_users(id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
        VALUES(:id,:email,'Credit Officer',:hash,decode(:sealed,'hex'),now(),'active')")
        ->execute(['id'=>$id,'email'=>$email,'hash'=>password_hash('credit-pass',PASSWORD_DEFAULT),'sealed'=>bin2hex($secret)]);
    $db->prepare("INSERT INTO central_staff_role_grants(id,staff_id,role,customer_scope_id,reason)
        VALUES(:id,:staff,:role,:customer,'Credit route fixture')")
        ->execute(['id'=>dcId(),'staff'=>$id,'role'=>$role,'customer'=>$customer]);
    return [$id,$email,$secret];
}

[$officerId,$officerEmail,$officerSecret]=dcStaff($db,'license_officer',$companyA);
[$readOnlyId,$readOnlyEmail,$readOnlySecret]=dcStaff($db,'license_supervisor',$companyA);

putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=true');putenv('CENTRAL_STAFF_PORTAL_ENABLED=true');
putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=true');
$headers=[];$cookies=[];$bodies=[];
$app=CentralStaffPortalOperatorWiring::build($db,str_repeat('c',32),
    static fn(string $v):string=>$v, static fn(string $v):string=>random_bytes(24).$v,
    static function(string $h)use(&$headers):void{$headers[]=$h;},
    static function(string $n,string $v,array $o)use(&$cookies):bool{$cookies[]=[$n,$v,$o];return true;},
    static function(string $b)use(&$bodies):void{$bodies[]=$b;},
    static fn():bool=>false,true);
$server=['HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'lichall.hafzatech.com',
    'HTTP_ORIGIN'=>'https://lichall.hafzatech.com','HTTP_SEC_FETCH_SITE'=>'same-origin',
    'REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'192.0.2.230','CONTENT_TYPE'=>'application/x-www-form-urlencoded'];
try {
    [$bag,$csrf]=dcLogin($app,$server,$officerEmail,'credit-pass',$officerSecret);
    $view=$server;$view['REQUEST_URI']='/staff/licenses/designer-credit';
    $app->serve($view,$bag,http_build_query(['staff_csrf'=>$csrf,'license_id'=>$licenseA]));
    dcAssert(str_contains(end($bodies),'Designer credit'),'authorized form missing');

    dcDeny(fn()=>$app->serve($view,$bag,http_build_query(['staff_csrf'=>str_repeat('a',64),'license_id'=>$licenseA])));
    dcDeny(fn()=>$app->serve($view,$bag,http_build_query(['staff_csrf'=>$csrf,'license_id'=>$licenseB])));

    $configure=$server;$configure['REQUEST_URI']='/staff/licenses/designer-credit/configure';
    $payload=['staff_csrf'=>$csrf,'license_id'=>$licenseA,'request_id'=>dcId(),'enabled'=>'1',
        'prefix'=>'Website by','label'=>'HAFZATECH','url'=>'https://hafzatech.com','alignment'=>'center'];
    $app->serve($configure,$bag,http_build_query($payload));
    dcAssert(str_contains(end($bodies),'Designer credit version 1'),'configuration did not save');
    dcAssert((int)$db->query("SELECT count(*) FROM central_license_designer_credits WHERE license_id='".$licenseA."'")->fetchColumn()===1,'credit row missing');
    dcAssert((int)$db->query("SELECT count(*) FROM central_designer_credit_audit WHERE license_id='".$licenseA."'")->fetchColumn()===1,'audit missing');

    [$readBag,$readCsrf]=dcLogin($app,$server,$readOnlyEmail,'credit-pass',$readOnlySecret);
    dcDeny(fn()=>$app->serve($configure,$readBag,http_build_query(
        array_merge($payload,['staff_csrf'=>$readCsrf,'request_id'=>dcId()]))));

    $db->prepare('UPDATE central_staff_role_grants SET revoked_at=now() WHERE staff_id=:staff')
        ->execute(['staff'=>$officerId]);
    dcDeny(fn()=>$app->serve($view,$bag,http_build_query(['staff_csrf'=>$csrf,'license_id'=>$licenseA])));
} finally {
    putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=false');putenv('CENTRAL_STAFF_PORTAL_ENABLED=false');
    putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=false');
}
echo "PASS: Designer credit route CSRF, customer scope, read-only denial, revocation and audit\n";