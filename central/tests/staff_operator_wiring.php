<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffPortalOperatorWiring;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach([
 'StaffRolePermissions','CentralStaffAccess',
 'CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
 'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
 'CentralStaffLoginRateLimiter','CentralStaffSessionService',
 'CentralStaffSessionAuthorization','CentralStaffCsrfTokenService',
 'CentralStaffHttpPolicy','CentralStaffProtectedHttpBoundary',
 'CentralStaffPaymentEvidenceQueue','CentralStaffPaymentEvidenceReviewService',
 'CentralStaffInvoicePaymentOverview','CentralStaffPaymentEvidenceHttpAdapter',
 'CentralStaffBillingDashboardRenderer','CentralStaffCustomerLookup',
 'CentralStaffPortalDashboard','CentralStaffSupportTicketQueue',
 'CentralStaffSupportQueueHtmlRenderer','CentralStaffSessionAuditReader',
 'CentralStaffAccountActionReader','CentralStaffPortalAuditHistoryPage',
 'CentralStaffLicenseSigningQueue','CentralStaffLicenseQueueHtmlRenderer',
 'CentralStaffAccountDirectory','CentralStaffRoleGrantDirectory',
 'SupportAgentReplyService','CentralStaffSessionSupportReply',
 'CentralStaffOwnPasswordChange','CentralStaffPortalOwnPassword',
 'CentralStaffPortalTeamDashboard','CentralStaffPortalHttpRouter',
 'CentralStaffPortalPrivateResponse','CentralStaffPortalFormRequest',
 'CentralStaffPortalOperatorKernel','CentralStaffPortalResponseEmitter',
 'CentralStaffPortalOperatorApp','CentralStaffPortalOperatorWiring'
] as $class) {
    require dirname(__DIR__).'/src/'.$class.'.php';
}
function opAssert(bool $ok,string $why):void {
 if(!$ok)throw new RuntimeException('Vendor bootstrap: '.$why);
}
function opReject(callable $fn,string $error):void {
 try{$fn();}catch(RegistrationError $e){
  opAssert($e->errorCode===$error,
   'expected '.$error.', got '.$e->errorCode);
  return;
 }
 throw new RuntimeException('Vendor bootstrap: expected '.$error);
}
$dsn=getenv('CENTRAL_DB_DSN');
opAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
 'isolated central PostgreSQL test DB required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$headers=[];$cookies=[];$bodies=[];
$writeHeader=static function(string $header)use(&$headers):void{
 $headers[]=$header;
};
$writeCookie=static function(string $name,string $value,array $options)
 use(&$cookies):bool{
 $cookies[]=[$name,$value,$options];return true;
};
$writeBody=static function(string $body)use(&$bodies):void{
 $bodies[]=$body;
};
$sent=static fn():bool=>false;
// TEST-ONLY non-secret synthetic closures. Real deployments must supply
// protected operator KMS/vault implementations with independent custody.
$decrypt=static fn(string $sealed):string=>$sealed;
$encrypt=static fn(string $body):string=>random_bytes(24).$body;
$args=[$db,str_repeat('k',32),$decrypt,$encrypt,
 $writeHeader,$writeCookie,$writeBody,$sent];
putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=false');
putenv('CENTRAL_STAFF_PORTAL_ENABLED=false');
putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=false');
opReject(fn()=>CentralStaffPortalOperatorWiring::build(...$args),
 'staff_bootstrap_not_enabled');
opReject(fn()=>CentralStaffPortalOperatorWiring::build(...[...$args,true]),
 'staff_bootstrap_not_enabled');
putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=true');
try {
 opReject(fn()=>CentralStaffPortalOperatorWiring::build(...$args),
  'staff_bootstrap_not_enabled');
 $tooShort=$args;$tooShort[1]='unsafe';
 opReject(fn()=>CentralStaffPortalOperatorWiring::build(...[...$tooShort,true]),
  'staff_operator_configuration_denied');
 $app=CentralStaffPortalOperatorWiring::build(...[...$args,true]);
 $server=['HTTPS'=>'on','SERVER_PORT'=>'443',
  'HTTP_HOST'=>'lichall.hafzatech.com',
  'HTTP_ORIGIN'=>'https://lichall.hafzatech.com',
  'HTTP_SEC_FETCH_SITE'=>'same-origin','REQUEST_METHOD'=>'POST',
  'REQUEST_URI'=>'/staff/session/login','REMOTE_ADDR'=>'192.0.2.210',
  'CONTENT_TYPE'=>'application/x-www-form-urlencoded'];
 $form='email=missing%40example.test&password=wrong&totp=123456';
 opReject(fn()=>$app->serve($server,[],$form),
  'staff_operator_not_enabled');
 opAssert($headers===[] && $cookies===[] && $bodies===[],
  'independent operator portal switch denies SAPI output');
 putenv('CENTRAL_STAFF_PORTAL_ENABLED=true');
 opReject(fn()=>$app->serve($server,[],$form),
  'staff_login_denied');
 opAssert($headers===[] && $cookies===[] && $bodies===[],
  'failed staff login never sets cookies or returns secrets');
 $untrusted=$server;$untrusted['HTTP_ORIGIN']='https://evil.example';
 opReject(fn()=>$app->serve($untrusted,[],$form),'staff_form_denied');
 $otherMethod=$server;$otherMethod['REQUEST_METHOD']='GET';
 opReject(fn()=>$app->serve($otherMethod,[],$form),'staff_form_denied');
 putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=false');
 opReject(fn()=>$app->serve($server,[],$form),
  'staff_bootstrap_not_enabled');
 opAssert($headers===[] && $cookies===[] && $bodies===[],
  'revoked global bootstrap disables previously assembled operator app');
}finally{
 putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=false');
 putenv('CENTRAL_STAFF_PORTAL_ENABLED=false');
 putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=false');
}
echo "PASS: strictly gated HAFZATECH operator wiring, isolated PostgreSQL, no public login, no secret/cookie output\n";
