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
    'DesignerCreditPolicy','CentralStaffDesignerCreditService','CentralStaffDesignerCreditRenderer','CentralStaffInstallationDirectory',
    'CentralStaffPortalHttpRouter','CentralStaffPortalPrivateResponse','CentralStaffPortalFormRequest',
    'CentralStaffPortalOperatorKernel','CentralStaffPortalResponseEmitter','CentralStaffPortalOperatorApp',
    'CentralStaffPortalOperatorWiring',
] as $class) require dirname(__DIR__).'/src/'.$class.'.php';

function ovAssert(bool $ok,string $why):void { if(!$ok) throw new RuntimeException('Central overview: '.$why); }
function ovReject(callable $fn):void {
    try { $fn(); } catch (RegistrationError $e) {
        ovAssert($e->errorCode==='staff_portal_denied','expected denial, got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Central overview: expected staff_portal_denied');
}
function ovId():string {$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
function ovOtp(string $secret):string {$step=intdiv(time(),30);$bits=pack('N2',intdiv($step,4294967296),$step%4294967296);$mac=hash_hmac('sha1',$bits,$secret,true);$at=ord($mac[19])&15;$n=unpack('N',substr($mac,$at,4))[1]&0x7fffffff;return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);}

$dsn=getenv('CENTRAL_DB_DSN');
ovAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),'isolated central DB required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

$customerA=ovId();$customerB=ovId();
$db->prepare("INSERT INTO vendor_customers(id,legal_name,status) VALUES(:id,:name,'active')")
    ->execute(['id'=>$customerA,'name'=>'<script>Overview Venue</script>']);
$db->prepare("INSERT INTO vendor_customers(id,legal_name,status) VALUES(:id,:name,'active')")
    ->execute(['id'=>$customerB,'name'=>'Isolated Other Venue']);

$installations=[];
foreach ([[$customerA,'alpha'], [$customerB,'beta']] as [$customer,$suffix]) {
    $enrollment=ovId();$installation=ovId();$domain='overview-'.$suffix.'-'.substr($enrollment,0,8).'.example.test';
    $db->prepare("INSERT INTO installation_enrollments(id,customer_id,token_sha256,approved_domain,expires_at)
        VALUES(:id,:customer,:token,:domain,now()+interval '1 day')")
        ->execute(['id'=>$enrollment,'customer'=>$customer,'token'=>hash('sha256',$enrollment),'domain'=>$domain]);
    $db->prepare("INSERT INTO registered_installations(id,customer_id,enrollment_id,approved_domain,ed25519_public_key,status)
        VALUES(:id,:customer,:enrollment,:domain,:key,'active')")
        ->execute(['id'=>$installation,'customer'=>$customer,'enrollment'=>$enrollment,'domain'=>$domain,
            'key'=>rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=')]);
    $installations[$suffix]=[$installation,$domain];
}
$db->prepare("INSERT INTO vendor_support_tickets(id,customer_id,installation_id,client_ticket_id,subject_ciphertext,content_sha256,status)
    VALUES(:id,:customer,:installation,:client,decode(:subject,'hex'),:hash,'new')")
    ->execute(['id'=>ovId(),'customer'=>$customerA,'installation'=>$installations['alpha'][0],
        'client'=>ovId(),'subject'=>bin2hex(random_bytes(24)),'hash'=>hash('sha256','overview')]);
$ticketNumber=(string)$db->query("SELECT ticket_number FROM vendor_support_tickets WHERE customer_id="
    .$db->quote($customerA))->fetchColumn();
ovAssert($ticketNumber!=='','support ticket fixture missing');

$secret='overview-'.bin2hex(random_bytes(8));
$staff=ovId();$email='central-overview-'.bin2hex(random_bytes(4)).'@example.test';
$db->prepare("INSERT INTO central_staff_users(id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
    VALUES(:id,:email,'Overview License Officer',:hash,decode(:sealed,'hex'),now(),'active')")
    ->execute(['id'=>$staff,'email'=>$email,'hash'=>password_hash('overview-pass',PASSWORD_DEFAULT),'sealed'=>bin2hex($secret)]);
foreach ([['license_officer','license.read_status'],['support_agent','support.ticket.read']] as [$role]) {
    $db->prepare("INSERT INTO central_staff_role_grants(id,staff_id,role,customer_scope_id,reason)
        VALUES(:id,:staff,:role,:customer,'Scoped overview fixture before login')")
        ->execute(['id'=>ovId(),'staff'=>$staff,'role'=>$role,'customer'=>$customerA]);
}

putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=true');putenv('CENTRAL_STAFF_PORTAL_ENABLED=true');
putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=true');
$headers=[];$cookies=[];$bodies=[];
$app=CentralStaffPortalOperatorWiring::build($db,str_repeat('o',32),
    static fn(string $v):string=>$v, static fn(string $v):string=>random_bytes(24).$v,
    static function(string $h)use(&$headers):void{$headers[]=$h;},
    static function(string $n,string $v,array $o)use(&$cookies):bool{$cookies[]=[$n,$v,$o];return true;},
    static function(string $b)use(&$bodies):void{$bodies[]=$b;},
    static fn():bool=>false,true);
$server=['HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'lichall.hafzatech.com',
    'HTTP_ORIGIN'=>'https://lichall.hafzatech.com','HTTP_SEC_FETCH_SITE'=>'same-origin',
    'REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'192.0.2.221','CONTENT_TYPE'=>'application/x-www-form-urlencoded'];
try {
    $login=$server;$login['REQUEST_URI']='/staff/session/login';
    $app->serve($login,[],http_build_query(['email'=>$email,'password'=>'overview-pass','totp'=>ovOtp($secret)]));
    ovAssert(count($cookies)===1&&strlen($cookies[0][1])===64,'staff login failed');
    $body=end($bodies);preg_match('/name="staff_csrf" value="([a-f0-9]{64})"/',$body,$m);
    ovAssert(isset($m[1]),'csrf missing');$csrf=$m[1];
    $bag=['__Host-HafzatechStaff'=>$cookies[0][1]];

    $headers=[];$bodies=[];$dash=$server;$dash['REQUEST_URI']='/staff/dashboard';
    $app->serve($dash,$bag,http_build_query(['staff_csrf'=>$csrf,'customer_id'=>$customerA]));
    $html=end($bodies);
    ovAssert(str_contains($html,$installations['alpha'][0]),'installation ID missing');
    ovAssert(str_contains($html,$installations['alpha'][1]),'installation domain missing');
    ovAssert(str_contains($html,'&lt;script&gt;Overview Venue&lt;/script&gt;'),'customer name not escaped');
    ovAssert(!str_contains($html,'<script>Overview Venue</script>'),'unescaped customer name leaked');
    ovAssert(str_contains($html,'1 open support ticket'),'open support count missing');
    ovAssert(str_contains($html,'No prepared license'),'empty license state missing');
    ovAssert(str_contains($html,'action="/staff/support/tickets"')
        && str_contains($html,'Installation support'),'installation support action missing');
    ovAssert(!str_contains($html,'Recent invoices'),'billing section visible without permission');

    $headers=[];$bodies=[];$support=$server;$support['REQUEST_URI']='/staff/support/tickets';
    $app->serve($support,$bag,http_build_query(['staff_csrf'=>$csrf,
        'customer_id'=>$customerA,'installation_id'=>$installations['alpha'][0]]));
    $supportHtml=end($bodies);
    ovAssert(str_contains($supportHtml,$ticketNumber),'selected installation ticket missing');
    ovAssert(!str_contains($supportHtml,$installations['beta'][0]),'foreign installation ticket leaked');

    $foreign=$server;$foreign['REQUEST_URI']='/staff/support/tickets';
    try {
        $app->serve($foreign,$bag,http_build_query(['staff_csrf'=>$csrf,
            'customer_id'=>$customerA,'installation_id'=>$installations['beta'][0]]));
        throw new RuntimeException('Central overview: foreign installation was accepted');
    } catch (RegistrationError $e) {
        ovAssert($e->errorCode==='support_scope_denied','expected support_scope_denied');
    }

    $headers=[];$bodies=[];$installationsPage=$server;$installationsPage['REQUEST_URI']='/staff/installations';
    $app->serve($installationsPage,$bag,http_build_query([
        'staff_csrf'=>$csrf,'customer_id'=>$customerA]));
    $installationsHtml=end($bodies);
    ovAssert(str_contains($installationsHtml,'Customer installation management')
        && str_contains($installationsHtml,$installations['alpha'][0])
        && str_contains($installationsHtml,$installations['alpha'][1]),
        'installation management directory missing record');
    ovAssert(!str_contains($installationsHtml,$installations['beta'][0]),
        'foreign customer installation visible');

    ovReject(fn()=>$app->serve($dash,$bag,http_build_query(['staff_csrf'=>$csrf,'customer_id'=>$customerB])));
    $db->prepare("UPDATE central_staff_role_grants SET revoked_at=now() WHERE staff_id=:staff")
        ->execute(['staff'=>$staff]);
    ovReject(fn()=>$app->serve($dash,$bag,http_build_query(['staff_csrf'=>$csrf,'customer_id'=>$customerA])));
} finally {
    putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=false');putenv('CENTRAL_STAFF_PORTAL_ENABLED=false');
    putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=false');
}
echo "PASS: Central customer overview data, escaping, isolation and post-revocation denial\n";