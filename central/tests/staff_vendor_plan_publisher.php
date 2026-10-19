<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffVendorPlanPublisher;
use Hafzatech\Central\CentralStaffSessionService;
use Hafzatech\Central\CentralStaffSessionAuthorization;
use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach([
    'StaffRolePermissions','CentralStaffAccess','CentralStaffPasswordVerifier',
    'CentralStaffTotpVerifier','CentralStaffMfaChallenge',
    'CentralStaffCredentialVerifier','CentralStaffLoginRateLimiter',
    'CentralStaffSessionService','CentralStaffSessionAuthorization',
    'CentralStaffVendorPlanPublisher','CentralStaffVendorPlanCatalogRenderer',
    'CentralStaffVendorPlanCatalogReader','CentralStaffPortalHome',
    'CentralStaffCsrfTokenService','CentralStaffHttpPolicy',
    'CentralStaffProtectedHttpBoundary',
    'CentralStaffPaymentEvidenceQueue','CentralStaffPaymentEvidenceReviewService',
    'CentralStaffInvoicePaymentOverview','CentralStaffPaymentEvidenceHttpAdapter',
    'CentralStaffPortalTeamDashboard','CentralStaffPortalAuditorDashboard',
    'CentralStaffPortalHttpRouter','CentralStaffPortalPrivateResponse'
] as $class)require dirname(__DIR__).'/src/'.$class.'.php';

function planAssert(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('Vendor plan publish: '.$why);
}
function planDeny(callable $action,string $code):void {
    try {$action();}catch(RegistrationError $e){
        planAssert($e->errorCode===$code,
            'expected '.$code.' got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Expected denial '.$code);
}
function planUuid():string {
    $b=random_bytes(16);
    $b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.
        substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function planTotp(string $seed):string {
    $n=intdiv(time(),30);
    $mac=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),$seed,true);
    $offset=ord($mac[19])&15;
    $v=unpack('N',substr($mac,$offset,4))[1]&0x7fffffff;
    return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
planAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'isolated central PostgreSQL required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$writer=planUuid();$bill=planUuid();
$seed='12345678901234567890';$password='synthetic-operator-plan-password';
$users=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Test vendor catalog manager',:hash,decode(:secret,'hex'),now(),'active')");
$roles=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason)
 VALUES(:id,:staff,:role,'Synthetic plan publish test')");
foreach([
 [$writer,'plan-admin','plan_administrator'],
 [$bill,'invoice-biller','billing_officer']
] as [$id,$name,$role]){
    $users->execute(['id'=>$id,'email'=>'publisher-'.$name.'@example.test',
        'hash'=>password_hash($password,PASSWORD_ARGON2ID),
        'secret'=>bin2hex($seed)]);
    $roles->execute(['id'=>planUuid(),'staff'=>$id,'role'=>$role]);
}
$sessions=new CentralStaffSessionService($db,
    new Hafzatech\Central\CentralStaffCredentialVerifier(
        new Hafzatech\Central\CentralStaffPasswordVerifier($db),
        new Hafzatech\Central\CentralStaffMfaChallenge(
            $db,static fn(string $sealed):string=>$sealed)),
    new Hafzatech\Central\CentralStaffLoginRateLimiter($db,str_repeat('P',32)));
$otp=planTotp($seed);
$writerSession=$sessions->login('publisher-plan-admin@example.test',
    $password,$otp,'192.0.2.243');
$billingSession=$sessions->login('publisher-invoice-biller@example.test',
    $password,$otp,'192.0.2.244');
planAssert(is_string($writerSession)&&is_string($billingSession),
    'independent synthetic staff MFA login');
$auth=new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db));
$off=new CentralStaffVendorPlanPublisher($db,$sessions,$auth);
$on=new CentralStaffVendorPlanPublisher($db,$sessions,$auth,true);
$request=planUuid();
$features=['booking'=>true,'reporting'=>false];
$args=[$writerSession,$request,'test_catalog_plan',6,'USD','12500',2,12,$features];
putenv('CENTRAL_VENDOR_PLAN_PUBLISH_ENABLED=false');
planDeny(fn()=>$on->publish(...$args),'vendor_plan_publish_not_enabled');
putenv('CENTRAL_VENDOR_PLAN_PUBLISH_ENABLED=true');
try {
    planDeny(fn()=>$off->publish(...$args),'vendor_plan_publish_not_enabled');
    $roleArgs=$args;$roleArgs[0]=$billingSession;
    planDeny(fn()=>$on->publish(...$roleArgs),
        'vendor_plan_administrator_required');
    $bad=$args;$bad[1]='not-uuid';
    planDeny(fn()=>$on->publish(...$bad),'invalid_vendor_plan_request');
    $bad=$args;$bad[3]=18;
    planDeny(fn()=>$on->publish(...$bad),'invalid_vendor_plan_request');
    $bad=$args;$bad[5]='00001';
    planDeny(fn()=>$on->publish(...$bad),'invalid_vendor_plan_request');
    $bad=$args;$bad[8]=['booking'=>'yes'];
    planDeny(fn()=>$on->publish(...$bad),'invalid_vendor_plan_request');
    $first=$on->publish(...$args);
    planAssert($first['version']===1 && $first['term_months']===6
       && $first['currency']==='USD' && $first['price_minor']==='12500',
       'new commercial plan exact first version and trusted price');
    planAssert($on->publish(...$args)===$first,
        'same MFA administrator same request returns original immutable version');
    $bad=$args;$bad[5]='13000';
    planDeny(fn()=>$on->publish(...$bad),'vendor_plan_request_conflict');
    $newArgs=$args;
    $newArgs[1]=planUuid();$newArgs[5]='13000';$newArgs[7]=15;
    $second=$on->publish(...$newArgs);
    planAssert($second['version']===2 &&
        $second['plan_version_id']!==$first['plan_version_id'] &&
        $second['price_minor']==='13000',
        'second published price creates a NEW version');
    planAssert($on->publish(...$args)===$first,
        'original request idempotency remains after later plan version');
    $q=$db->prepare('SELECT version,price_minor,entitlements_snapshot
        FROM central_vendor_plan_versions
        WHERE id=:id');
    $q->execute(['id'=>$first['plan_version_id']]);
    $old=$q->fetch(PDO::FETCH_ASSOC);
    $snapshot=json_decode($old['entitlements_snapshot'],true,32,JSON_THROW_ON_ERROR);
    planAssert((int)$old['version']===1 && (int)$old['price_minor']===12500 &&
        $snapshot['max_halls']===2 && $snapshot['max_staff']===12,
        'historic plan quote and entitlements not rebased');
    $q=$db->prepare("SELECT count(*) FROM central_vendor_plan_publish_events
      WHERE request_id IN (:one,:two)");
    $q->execute(['one'=>$request,'two'=>$newArgs[1]]);
    planAssert((int)$q->fetchColumn()===2,
        'one immutable actor-provenance event per distinct published version');
    try {
        $db->prepare('DELETE FROM central_vendor_plan_publish_events
            WHERE request_id=:req')->execute(['req'=>$request]);
        throw new RuntimeException('Unexpected deletion of plan publication audit');
    }catch(PDOException $e){
        planAssert($e->getCode()==='P0001',
            'publication audit rows cannot be deleted or modified');
    }
    $q=$db->prepare('SELECT count(*) FROM central_vendor_invoices
        WHERE plan_version_id IN (:one,:two)');
    $q->execute(['one'=>$first['plan_version_id'],
        'two'=>$second['plan_version_id']]);
    planAssert((int)$q->fetchColumn()===0,
        'publishing vendor plans never charges customers or issues invoices');
    // Protected INTERNAL plan administrator form and publish receipt;
    // vendor staff identity comes from secure cookie and current session.
    $reader=new Hafzatech\Central\CentralStaffVendorPlanCatalogReader(
        $db,$auth);
    $recent=$reader->recent($writerSession,25);
    $ownVersions=array_values(array_filter($recent,
        fn(array $row):bool=>$row['plan_code']==='test_catalog_plan'));
    planAssert(count($ownVersions)===2 &&
        array_column($ownVersions,'version')!==[],
        'audited Plan Administrator sees bounded historical version list');
    planDeny(fn()=>$reader->recent($billingSession),
        'vendor_plan_read_denied');
    planDeny(fn()=>$reader->recent($writerSession,26),
        'invalid_vendor_plan_catalog_request');
    $csrfService=new Hafzatech\Central\CentralStaffCsrfTokenService($db,$sessions);
    $writerCsrf=$csrfService->issue($writerSession);
    $billingCsrf=$csrfService->issue($billingSession);
    planAssert(is_string($writerCsrf)&&is_string($billingCsrf),
        'independent current session CSRF generated');
    $boundary=new Hafzatech\Central\CentralStaffProtectedHttpBoundary(
        $sessions,$csrfService,$auth,true);
    $billingAdapter=new Hafzatech\Central\CentralStaffPaymentEvidenceHttpAdapter(
        $db,$boundary,
        new Hafzatech\Central\CentralStaffPaymentEvidenceQueue($db,$auth),
        new Hafzatech\Central\CentralStaffPaymentEvidenceReviewService(
            $db,$sessions,$auth),
        new Hafzatech\Central\CentralStaffInvoicePaymentOverview(
            $db,$sessions,$auth),false);
    $router=new Hafzatech\Central\CentralStaffPortalHttpRouter(
        $boundary,$billingAdapter,true,planPublisher:$on,planCatalog:$reader);
    $server=[
        'HTTPS'=>'on','SERVER_PORT'=>'443',
        'HTTP_HOST'=>'lichall.hafzatech.com',
        'HTTP_ORIGIN'=>'https://lichall.hafzatech.com',
        'HTTP_SEC_FETCH_SITE'=>'same-origin',
        'REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/staff/plans',
        'REMOTE_ADDR'=>'192.0.2.243'
    ];
    $cookie=[Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME=>$writerSession];
    $otherCookie=[Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME=>$billingSession];
    $form=$router->dispatch($server,$cookie,[],$writerCsrf);
    planAssert($form['kind']==='private_vendor_plan_catalog_html' &&
        str_contains($form['html'],'action="/staff/plans/publish"') &&
        str_contains($form['html'],'name="staff_csrf"') &&
        str_contains($form['html'],'Recent immutable plan versions') &&
        str_contains($form['html'],'test_catalog_plan') &&
        !str_contains($form['html'],$writerSession),
        'protected vendor Plan Administrator sees form, never cookie bearer');
    $writerOutput=(new Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
        ->compose($form);
    planAssert($writerOutput['cookies']===[] &&
        $writerOutput['headers']['Cache-Control']==='no-store, private',
        'vendor plan entry form cannot be cached or set session cookie');
    planDeny(fn()=>$router->dispatch($server,$otherCookie,[],$billingCsrf),
        'staff_portal_denied');
    $publishServer=$server;$publishServer['REQUEST_URI']='/staff/plans/publish';
    $fields=['request_id'=>planUuid(),'plan_code'=>'portal_plan_catalog',
        'term_months'=>'12','currency'=>'USD','price_minor'=>'25000',
        'max_halls'=>'3','max_staff'=>'15','features_json'=>'{"booking":true}'];
    $published=$router->dispatch($publishServer,$cookie,$fields,$writerCsrf);
    planAssert($published['kind']==='private_vendor_plan_published_html' &&
        str_contains($published['html'],'Plan version published') &&
        str_contains($published['html'],'25000') &&
        !str_contains($published['html'],$writerSession),
        'separately gated MFA plan publisher produced safe private receipt');
    $same=$router->dispatch($publishServer,$cookie,$fields,$writerCsrf);
    planAssert($same===$published,
        'idempotent staff POST reuses original published plan version');
    $response=(new Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
        ->compose($published);
    planAssert($response['cookies']===[] &&
        $response['headers']['Cache-Control']==='no-store, private',
        'published version never leaks tokens into response');
    planDeny(fn()=>$router->dispatch(
        $publishServer,$otherCookie,$fields,$billingCsrf),'staff_portal_denied');
    planDeny(fn()=>$router->dispatch(
        $publishServer,$cookie,$fields,str_repeat('0',64)),'staff_portal_denied');
    $badOrigin=$publishServer;
    $badOrigin['HTTP_ORIGIN']='https://malicious.example';
    planDeny(fn()=>$router->dispatch(
        $badOrigin,$cookie,$fields,$writerCsrf),'staff_portal_denied');
    planDeny(fn()=>$router->dispatch(
        $publishServer,$cookie,$fields+['paid'=>true],$writerCsrf),
        'invalid_vendor_plan_request');
    $badJson=$fields;$badJson['features_json']='{"booking":"paid"}';
    planDeny(fn()=>$router->dispatch(
        $publishServer,$cookie,$badJson,$writerCsrf),
        'invalid_vendor_plan_request');
    $huge=$fields;$huge['features_json']=str_repeat('A',2049);
    planDeny(fn()=>$router->dispatch(
        $publishServer,$cookie,$huge,$writerCsrf),
        'invalid_vendor_plan_request');
    $forgedReceipt=['plan_version_id'=>planUuid(),'version'=>1,
        'term_months'=>6,'currency'=>'<script>','price_minor'=>'1'];
    planDeny(fn()=>Hafzatech\Central\CentralStaffVendorPlanCatalogRenderer::receipt(
        $forgedReceipt),'invalid_vendor_plan_response');
    $homeService=new Hafzatech\Central\CentralStaffPortalHome(
        $sessions,$auth);
    $homeRouter=new Hafzatech\Central\CentralStaffPortalHttpRouter(
        $boundary,$billingAdapter,true,staffHome:$homeService);
    $homeServer=$server;$homeServer['REQUEST_URI']='/staff/home';
    $home=$homeRouter->dispatch($homeServer,$cookie,[],$writerCsrf);
    planAssert($home['kind']==='private_staff_home_html' &&
        str_contains($home['html'],'action="/staff/plans"') &&
        str_contains($home['html'],'action="/staff/dashboard"') &&
        str_contains($home['html'],'action="/staff/session/logout"') &&
        !str_contains($home['html'],'action="/staff/team/dashboard"') &&
        !str_contains($home['html'],'action="/staff/audit/dashboard"') &&
        !str_contains($home['html'],$writerSession),
        'Plan Administrator home offers only current capability navigation');
    $homeReply=(new Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
        ->compose($home);
    planAssert($homeReply['status']===200 &&
        $homeReply['cookies']===[] &&
        $homeReply['headers']['Cache-Control']==='no-store, private' &&
        str_contains($homeReply['headers']['Content-Security-Policy'],
            "default-src 'none'"),
        'private home has no cookies, no cache and restrictive CSP');
    // Real CURRENT MFA staff roles, never browser role claims, decide which
    // operator navigation is visible. Distinct MFA staff credentials prove
    // auditor and team roles cannot see plan-publishing controls.
    $auditorId=planUuid();$teamId=planUuid();
    foreach([
        [$auditorId,'publisher-auditor@example.test','auditor'],
        [$teamId,'publisher-team-admin@example.test','staff_administrator']
    ] as [$person,$email,$role]) {
        $users->execute(['id'=>$person,'email'=>$email,
            'hash'=>password_hash($password,PASSWORD_ARGON2ID),
            'secret'=>bin2hex($seed)]);
        $roles->execute(['id'=>planUuid(),'staff'=>$person,'role'=>$role]);
    }
    $auditorSession=$sessions->login('publisher-auditor@example.test',
        $password,planTotp($seed),'192.0.2.245');
    $teamSession=$sessions->login('publisher-team-admin@example.test',
        $password,planTotp($seed),'192.0.2.246');
    planAssert(is_string($auditorSession)&&is_string($teamSession),
        'separate auditor/team staff login uses real MFA sessions');
    $auditorCsrf=$csrfService->issue($auditorSession);
    $teamCsrf=$csrfService->issue($teamSession);
    $auditorPage=$homeRouter->dispatch($homeServer,[
        Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME=>$auditorSession
    ],[],$auditorCsrf);
    planAssert(str_contains($auditorPage['html'],'action="/staff/audit/dashboard"') &&
        !str_contains($auditorPage['html'],'action="/staff/plans"') &&
        !str_contains($auditorPage['html'],'action="/staff/team/dashboard"'),
        'auditor sees only permitted global audit section');
    $teamPage=$homeRouter->dispatch($homeServer,[
        Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME=>$teamSession
    ],[],$teamCsrf);
    planAssert(str_contains($teamPage['html'],'action="/staff/team/dashboard"') &&
        !str_contains($teamPage['html'],'action="/staff/plans"') &&
        !str_contains($teamPage['html'],'action="/staff/audit/dashboard"'),
        'team administrator cannot navigate pricing or independent audit');
    $teamDashboard=new Hafzatech\Central\CentralStaffPortalTeamDashboard($auth);
    $auditorDashboard=new Hafzatech\Central\CentralStaffPortalAuditorDashboard($auth);
    $teamDetail=$teamDashboard->render($teamSession,$teamCsrf);
    $auditDetail=$auditorDashboard->render($auditorSession,$auditorCsrf);
    planAssert(str_contains($teamDetail,'action="/staff/home"') &&
        str_contains($teamDetail,'name="staff_csrf"') &&
        !str_contains($teamDetail,$teamSession),
        'current Team Administrator can navigate back without leaking bearer');
    planAssert(str_contains($auditDetail,'action="/staff/home"') &&
        str_contains($auditDetail,'name="staff_csrf"') &&
        !str_contains($auditDetail,$auditorSession),
        'current Auditor can navigate back without leaking bearer');
    planDeny(fn()=>$auditorDashboard->render($teamSession,$teamCsrf),
        'staff_auditor_dashboard_denied');
    planDeny(fn()=>$teamDashboard->render($auditorSession,$auditorCsrf),
        'staff_team_dashboard_denied');
    $billingHome=$homeRouter->dispatch($homeServer,$otherCookie,[],$billingCsrf);
    planAssert(!str_contains($billingHome['html'],'action="/staff/plans"') &&
        str_contains($billingHome['html'],'action="/staff/dashboard"'),
        'Billing Officer cannot discover privileged plan publishing action');
    planDeny(fn()=>$homeRouter->dispatch(
        $homeServer,$cookie,['role'=>'super_administrator'],$writerCsrf),
        'invalid_staff_home_request');
    planDeny(fn()=>$homeRouter->dispatch(
        $homeServer,$cookie,[],str_repeat('0',64)),'staff_portal_denied');
    $homeBadOrigin=$homeServer;
    $homeBadOrigin['HTTP_ORIGIN']='https://untrusted.example';
    planDeny(fn()=>$homeRouter->dispatch(
        $homeBadOrigin,$cookie,[],$writerCsrf),'staff_portal_denied');
    $homeGet=$homeServer;$homeGet['REQUEST_METHOD']='GET';
    planDeny(fn()=>$homeRouter->dispatch(
        $homeGet,$cookie,[],$writerCsrf),'staff_portal_denied');
    putenv('CENTRAL_VENDOR_PLAN_PUBLISH_ENABLED=false');
    try{
        $gatedHome=$homeRouter->dispatch($homeServer,$cookie,[],$writerCsrf);
        planAssert(!str_contains($gatedHome['html'],'action="/staff/plans"'),
            'unreleased plan publishing is hidden even for authorized staff');
    }finally{
        putenv('CENTRAL_VENDOR_PLAN_PUBLISH_ENABLED=true');
    }
    $loginResponse=(new Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
        ->compose(['kind'=>'internal_login','credentials'=>[
            'cookie_name'=>Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME,
            'cookie_value'=>$writerSession,
            'cookie_options'=>Hafzatech\Central\CentralStaffHttpPolicy::cookieOptions(time()),
            'csrf'=>$writerCsrf,
        ]]);
    planAssert($loginResponse['status']===200 &&
        count($loginResponse['cookies'])===1 &&
        $loginResponse['cookies'][0]['options']['httponly']===true &&
        str_contains($loginResponse['body'],'action="/staff/home"') &&
        str_contains($loginResponse['body'],'name="staff_csrf"') &&
        !str_contains($loginResponse['body'],$writerSession),
        'successful MFA login offers POST navigation without exposing bearer');
    planAssert(str_contains($form['html'],'action="/staff/home"') &&
        str_contains($published['html'],'action="/staff/home"') &&
        !str_contains($published['html'],$writerSession),
        'Plan Administrator form and receipt return safely to staff home');
    $db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
        WHERE staff_id=:staff")->execute(['staff'=>$writer]);
    planDeny(fn()=>$homeRouter->dispatch(
        $homeServer,$cookie,[],$writerCsrf),'staff_portal_denied');

    $revokedRequest=$fields;$revokedRequest['request_id']=planUuid();
    planDeny(fn()=>$router->dispatch(
        $publishServer,$cookie,$revokedRequest,$writerCsrf),'staff_portal_denied');

    $revokeArgs=$newArgs;$revokeArgs[1]=planUuid();
    planDeny(fn()=>$on->publish(...$revokeArgs),
        'vendor_plan_administrator_required');
}finally{
    putenv('CENTRAL_VENDOR_PLAN_PUBLISH_ENABLED=false');
}
echo "PASS: central MFA plan administrator publishes immutable versioned 6/12 month prices with idempotent audit and denied unverified payment\n";
