<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffRoleRequestService;
use Hafzatech\Central\CentralStaffRoleDecisionService;
use Hafzatech\Central\CentralStaffRoleReviewQueue;
use Hafzatech\Central\CentralStaffPortalTeamDashboard;
use Hafzatech\Central\CentralStaffSessionAuthorization;
use Hafzatech\Central\CentralStaffCsrfTokenService;
use Hafzatech\Central\CentralStaffHttpPolicy;
use Hafzatech\Central\CentralStaffProtectedHttpBoundary;
use Hafzatech\Central\CentralStaffPortalHttpRouter;
use Hafzatech\Central\CentralStaffPortalPrivateResponse;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach([
 'StaffRolePermissions','CentralStaffAccess','CentralStaffPasswordVerifier',
 'CentralStaffTotpVerifier','CentralStaffMfaChallenge',
 'CentralStaffCredentialVerifier','CentralStaffLoginRateLimiter',
 'CentralStaffSessionService','CentralStaffRoleRequestService',
 'CentralStaffRoleDecisionService','CentralStaffRoleReviewQueue',
 'CentralStaffPortalTeamDashboard',
 'CentralStaffSessionAuthorization','CentralStaffCsrfTokenService',
 'CentralStaffHttpPolicy','CentralStaffProtectedHttpBoundary',
 'CentralStaffPortalHttpRouter','CentralStaffPortalPrivateResponse',
 'CentralStaffPaymentEvidenceHttpAdapter','CentralStaffPaymentEvidenceQueue',
 'CentralStaffPaymentEvidenceReviewService','CentralStaffInvoicePaymentOverview'
] as $class)require dirname(__DIR__).'/src/'.$class.'.php';
function rolesCheck(bool $ok,string $why):void {
 if(!$ok)throw new RuntimeException('Central role request: '.$why);
}
function rolesDeny(callable $fn,string $code):void {
 try{$fn();}catch(RegistrationError $e) {
  rolesCheck($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);
  return;
 }
 throw new RuntimeException('Central role request: missing '.$code);
}
function rolesUuid():string{
 $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
 $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
 return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
  substr($h,16,4).'-'.substr($h,20);
}
function rolesTotp(string $seed):string{
 $n=intdiv(time(),30);
 $m=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),
 $seed,true);
 $p=ord($m[19])&15;$val=unpack('N',substr($m,$p,4))[1]&0x7fffffff;
 return str_pad((string)($val%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
rolesCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
 'isolated test PostgreSQL is mandatory');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seed='12345678901234567890';$password='synthetic-role-request-password';
$staff=[];
$insertStaff=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic Role Actor',:hash,decode(:seed,'hex'),now(),:status)");
$g=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:id,:staff,:role,'Synthetic role approval fixture')");
foreach(['admin'=>'staff_administrator','support'=>'support_agent',
 'super'=>'super_administrator','approver'=>'staff_administrator',
 'target'=>null] as $name=>$role){
 $id=rolesUuid();$staff[$name]=$id;
 $insertStaff->execute(['id'=>$id,'email'=>'role-intent-'.$name.'@example.test',
  'hash'=>password_hash($password,PASSWORD_ARGON2ID),
  'seed'=>bin2hex($seed),'status'=>$name==='target'?'pending_mfa':'active']);
 if($role!==null)$g->execute(['id'=>rolesUuid(),'staff'=>$id,'role'=>$role]);
}
$db->prepare("UPDATE central_staff_users SET mfa_secret_ciphertext=NULL,
 mfa_enrolled_at=NULL WHERE id=:target")->execute(['target'=>$staff['target']]);
$sessions=new Hafzatech\Central\CentralStaffSessionService($db,
 new Hafzatech\Central\CentralStaffCredentialVerifier(
  new Hafzatech\Central\CentralStaffPasswordVerifier($db),
  new Hafzatech\Central\CentralStaffMfaChallenge(
   $db,static fn(string $cipher):string=>$cipher)),
 new Hafzatech\Central\CentralStaffLoginRateLimiter($db,str_repeat('R',32)));
$code=rolesTotp($seed);
$adminBearer=$sessions->login('role-intent-admin@example.test',
 $password,$code,'192.0.2.188');
$supportBearer=$sessions->login('role-intent-support@example.test',
 $password,$code,'192.0.2.189');
$superBearer=$sessions->login('role-intent-super@example.test',
 $password,$code,'192.0.2.190');
$approverBearer=$sessions->login('role-intent-approver@example.test',
 $password,$code,'192.0.2.191');
rolesCheck(is_string($adminBearer)&&is_string($supportBearer)&&
 is_string($superBearer)&&is_string($approverBearer),'separate vendor MFA sessions');
$access=new Hafzatech\Central\CentralStaffAccess($db);
$off=new CentralStaffRoleRequestService($db,$sessions,$access);
$on=new CentralStaffRoleRequestService($db,$sessions,$access,true);
$decisionOff=new CentralStaffRoleDecisionService($db,$sessions,$access);
$decisionOn=new CentralStaffRoleDecisionService($db,$sessions,$access,true);
$id=rolesUuid();
putenv('CENTRAL_STAFF_ROLE_REQUEST_ENABLED=false');
rolesDeny(fn()=>$on->request($adminBearer,$id,$staff['target'],
 'support_agent',null,'Assign support after independent approval'),
 'staff_role_requests_not_enabled');
putenv('CENTRAL_STAFF_ROLE_REQUEST_ENABLED=true');
try {
 rolesDeny(fn()=>$off->request($adminBearer,$id,$staff['target'],
  'support_agent',null,'Assign support after independent approval'),
  'staff_role_requests_not_enabled');
 rolesDeny(fn()=>$on->request($supportBearer,$id,$staff['target'],
  'support_agent',null,'Assign support after independent approval'),
  'staff_role_request_denied');
 rolesDeny(fn()=>$on->request($adminBearer,$id,$staff['admin'],
  'support_agent',null,'Self role elevation attempt'),
  'staff_role_request_denied');
 rolesDeny(fn()=>$on->request($adminBearer,'invalid',$staff['target'],
  'support_agent',null,'Malformed request identifier'),
  'invalid_staff_role_request');
 rolesDeny(fn()=>$on->request($adminBearer,$id,$staff['target'],
  'super_administrator',null,'Unauthorized top level role'),
  'staff_role_request_denied');
 $scopeId=rolesUuid();
 $db->prepare("INSERT INTO vendor_customers(id,legal_name)
  VALUES(:id,'Synthetic scoped vendor role')")->execute(['id'=>$scopeId]);
 $scopedActor=rolesUuid();
 $insertStaff->execute(['id'=>$scopedActor,'email'=>'role-scoped-actor@example.test',
  'hash'=>password_hash($password,PASSWORD_ARGON2ID),
  'seed'=>bin2hex($seed),'status'=>'active']);
 $db->prepare("INSERT INTO central_staff_role_grants
  (id,staff_id,role,customer_scope_id,reason)
  VALUES(:id,:staff,'super_administrator',:scope,
   'Scoped role must not issue global HAFZATECH roles')")
  ->execute(['id'=>rolesUuid(),'staff'=>$scopedActor,'scope'=>$scopeId]);
 $scopedBearer=$sessions->login('role-scoped-actor@example.test',
  $password,$code,'192.0.2.193');
 rolesCheck(is_string($scopedBearer),'independent scoped actor MFA login');
 rolesDeny(fn()=>$on->request($scopedBearer,rolesUuid(),$staff['target'],
  'super_administrator',null,'Scoped Super Admin cannot request global role'),
  'staff_role_request_denied');

 $res=$on->request($adminBearer,$id,$staff['target'],
  'support_agent',null,'Assign support after independent approval');
 rolesCheck($res===['request_id'=>$id,'target_staff_id'=>$staff['target'],
  'role'=>'support_agent','state'=>'pending_independent_approval'],
  'proposed role is not active');
 rolesCheck($on->request($adminBearer,$id,$staff['target'],
  'support_agent',null,'Assign support after independent approval')===$res,
  'exact repeat is idempotent');
 rolesDeny(fn()=>$on->request($adminBearer,$id,$staff['target'],
  'billing_officer',null,'Changed after submission'),
  'staff_role_request_conflict');
 $q=$db->prepare("SELECT count(*) FROM central_staff_role_requests WHERE id=:id");
 $q->execute(['id'=>$id]);
 rolesCheck((int)$q->fetchColumn()===1,'one immutable requested role');
 $q=$db->prepare("SELECT count(*) FROM central_staff_role_grants
  WHERE staff_id=:id");
 $q->execute(['id'=>$staff['target']]);
 rolesCheck((int)$q->fetchColumn()===0,
  'pending staff role request never grants a capability');
 rolesCheck(!$access->permits($staff['target'],'support.reply',null,true),
  'pending staff with no MFA cannot use requested role');
 $high=$on->request($superBearer,rolesUuid(),$staff['target'],
  'super_administrator',null,'Independent super admin review required');
 rolesCheck($high['state']==='pending_independent_approval',
  'even super administrator request grants no immediate privilege');
 $rejectIntent=$on->request($adminBearer,rolesUuid(),$staff['target'],
  'plan_administrator',null,'Independent plan role review required');
 $futureIntent=$on->request($adminBearer,rolesUuid(),$staff['target'],
  'support_supervisor',null,'Later independent review after stale grants');
 $decisionId=rolesUuid();
 putenv('CENTRAL_STAFF_ROLE_DECISIONS_ENABLED=false');
 rolesDeny(fn()=>$decisionOn->decide($approverBearer,$id,$decisionId,
  'approved','Separate authenticated permission approval'),
  'staff_role_decisions_not_enabled');
 putenv('CENTRAL_STAFF_ROLE_DECISIONS_ENABLED=true');


 rolesDeny(fn()=>$decisionOff->decide($approverBearer,$id,$decisionId,
  'approved','Separate authenticated permission approval'),
  'staff_role_decisions_not_enabled');
 rolesDeny(fn()=>$decisionOn->decide($adminBearer,$id,$decisionId,
  'approved','Requester cannot approve own grant'),
  'staff_role_decision_denied');
 rolesDeny(fn()=>$decisionOn->decide($supportBearer,$id,$decisionId,
  'approved','Non administrator cannot approve grant'),
  'staff_role_decision_denied');
 rolesDeny(fn()=>$decisionOn->decide($approverBearer,$high['request_id'],
  rolesUuid(),'approved','Only second Super Administrator can approve'),
  'staff_role_decision_denied');
 $scopedReviewer=rolesUuid();
 $insertStaff->execute(['id'=>$scopedReviewer,'email'=>'role-scoped-review@example.test',
  'hash'=>password_hash($password,PASSWORD_ARGON2ID),
  'seed'=>bin2hex($seed),'status'=>'active']);
 $db->prepare("INSERT INTO central_staff_role_grants
  (id,staff_id,role,customer_scope_id,reason)
  VALUES(:id,:staff,'super_administrator',:scope,
   'Scoped reviewer cannot approve global vendor Super Admin')")
  ->execute(['id'=>rolesUuid(),'staff'=>$scopedReviewer,'scope'=>$scopeId]);
 $scopedReviewBearer=$sessions->login('role-scoped-review@example.test',
  $password,$code,'192.0.2.194');
 rolesCheck(is_string($scopedReviewBearer),'independent scoped reviewer MFA');
 rolesDeny(fn()=>$decisionOn->decide($scopedReviewBearer,$high['request_id'],
  rolesUuid(),'approved','Scoped reviewer cannot approve global role'),
  'staff_role_decision_denied');

 $auth=new CentralStaffSessionAuthorization($sessions,$access);
 $queue=new CentralStaffRoleReviewQueue($db,$sessions,$auth);
 rolesDeny(fn()=>$queue->listPending($supportBearer),
  'staff_review_queue_denied');
 rolesDeny(fn()=>$queue->listPending($approverBearer,26),
  'invalid_staff_review_queue');
 $pending=$queue->listPending($approverBearer);
 putenv('CENTRAL_STAFF_ROLE_DECISIONS_ENABLED=false');
 $hiddenReview=CentralStaffRoleReviewQueue::html($pending,
  str_repeat('b',64));
 putenv('CENTRAL_STAFF_ROLE_DECISIONS_ENABLED=true');
 rolesCheck(!str_contains($hiddenReview,'action="/staff/team/role-decisions"'),
  'independent decision UI hidden while operator decision gate is OFF');

 rolesCheck(in_array($id,array_column($pending,'request_id'),true) &&
  in_array($high['request_id'],array_column($pending,'request_id'),true),
  'independent second administrator sees bounded pending metadata queue');
 $selfReview=$queue->listPending($adminBearer);
 rolesCheck(!in_array($id,array_column($selfReview,'request_id'),true),
  'requesting administrator cannot review own submitted role request');
 $malicious=CentralStaffRoleReviewQueue::html([[
  'request_id'=>$id,'target_staff_id'=>$staff['target'],
  'role'=>'<script>alert(1)</script>',
  'customer_scope_id'=>'<img src=x onerror=alert(2)>',
  'created_at'=>'2026-10-18'
 ]]);
 rolesCheck(str_contains($malicious,'&lt;script&gt;') &&
  str_contains($malicious,'&lt;img') &&
  !str_contains($malicious,'<script>') &&
  !str_contains($malicious,'<img'),
  'untrusted role review metadata HTML escaped');
 $csrf=new CentralStaffCsrfTokenService($db,$sessions);
 $approvalCsrf=$csrf->issue($approverBearer);
 $requesterCsrf=$csrf->issue($adminBearer);
 $boundary=new CentralStaffProtectedHttpBoundary(
  $sessions,$csrf,$auth,true);
 $billing=new Hafzatech\Central\CentralStaffPaymentEvidenceHttpAdapter(
  $db,$boundary,
  new Hafzatech\Central\CentralStaffPaymentEvidenceQueue($db,$auth),
  new Hafzatech\Central\CentralStaffPaymentEvidenceReviewService(
   $db,$sessions,$auth),
  new Hafzatech\Central\CentralStaffInvoicePaymentOverview(
   $db,$sessions,$auth));
 $route=new CentralStaffPortalHttpRouter(
  $boundary,$billing,true,null,null,null,null,null,null,null,null,null,
  null,null,null,$queue,$decisionOn,$on);
 $http=['HTTPS'=>'on','SERVER_PORT'=>'443',
  'HTTP_HOST'=>'lichall.hafzatech.com','REQUEST_METHOD'=>'POST',
  'REQUEST_URI'=>'/staff/team/role-requests',
  'HTTP_ORIGIN'=>'https://lichall.hafzatech.com',
  'HTTP_SEC_FETCH_SITE'=>'same-origin','REMOTE_ADDR'=>'192.0.2.191'];
 $reviewCookie=[CentralStaffHttpPolicy::COOKIE_NAME=>$approverBearer];
 $submitHttp=$http;
 $submitHttp['REQUEST_URI']='/staff/team/role-request';
 $requesterCookie=[CentralStaffHttpPolicy::COOKIE_NAME=>$adminBearer];
 $submission=['request_id'=>$id,'target_staff_id'=>$staff['target'],
  'role'=>'support_agent','customer_scope_id'=>'',
  'reason'=>'Assign support after independent approval'];
 $requestReceipt=$route->dispatch(
  $submitHttp,$requesterCookie,$submission,$requesterCsrf);
 rolesCheck($requestReceipt['kind']==='private_role_request_receipt_html' &&
  str_contains($requestReceipt['html'],'not granted') &&
  str_contains($requestReceipt['html'],$id) &&
  !str_contains($requestReceipt['html'],$adminBearer) &&
  !str_contains($requestReceipt['html'],$submission['reason']),
  'same authorized Staff Administrator can replay exact proposal via protected route');
 $writeReceipt=(new CentralStaffPortalPrivateResponse(true))->compose(
  $requestReceipt);
 rolesCheck($writeReceipt['cookies']===[] &&
  $writeReceipt['headers']['Cache-Control']==='no-store, private',
  'proposal receipt cannot reveal session or become public cache');
 rolesDeny(fn()=>$route->dispatch($submitHttp,$reviewCookie,
  $submission,$approvalCsrf),'staff_role_request_conflict');
 rolesDeny(fn()=>$route->dispatch($submitHttp,$requesterCookie,
  $submission+['staff_id'=>$staff['admin']],$requesterCsrf),
  'invalid_staff_role_request');
 rolesDeny(fn()=>$route->dispatch($submitHttp,$requesterCookie,
  $submission,str_repeat('0',64)),'staff_portal_denied');
 rolesDeny(fn()=>$route->dispatch(array_replace($submitHttp,[
  'HTTP_ORIGIN'=>'https://evil.example']),
  $requesterCookie,$submission,$requesterCsrf),
  'staff_portal_denied');
 $team=new CentralStaffPortalTeamDashboard($auth);
 $newProposalForm=$team->render($adminBearer,$requesterCsrf);
 rolesCheck(str_contains($newProposalForm,'action="/staff/team/role-request"') &&
  str_contains($newProposalForm,'Select a role') &&
  str_contains($newProposalForm,'name="customer_scope_id"') &&
  str_contains($newProposalForm,'name="request_id"') &&
  !str_contains($newProposalForm,$adminBearer),
  'operator-gated Staff Admin role proposal has no preselected privileged role');
 $openReview=$route->dispatch($http,$reviewCookie,[],$approvalCsrf);
 rolesCheck(str_contains($openReview['html'],
   'action="/staff/team/role-decisions"') &&
  str_contains($openReview['html'],'name="decision_id"') &&
  str_contains($openReview['html'],$approvalCsrf) &&
  !str_contains($openReview['html'],$approverBearer),
  'reviewer gets per-row CSRF-bound decision form only when enabled');
 $reviewPage=$route->dispatch($http,$reviewCookie,[],$approvalCsrf);
 rolesCheck($reviewPage['kind']==='private_role_review_html' &&
  str_contains($reviewPage['html'],$id) &&
  !str_contains($reviewPage['html'],$approverBearer) &&
  !str_contains($reviewPage['html'],'synthetic-role-request-password'),
  'independent admin sees only metadata with no password/session bearer');
 $view=(new CentralStaffPortalPrivateResponse(true))->compose($reviewPage);
 rolesCheck($view['cookies']===[] &&
  $view['headers']['Cache-Control']==='no-store, private',
  'pending role review private no-store and cookie-free');
 $fake=[CentralStaffHttpPolicy::COOKIE_NAME=>$supportBearer];
 rolesDeny(fn()=>$route->dispatch($http,$fake,[],$approvalCsrf),
  'staff_portal_denied');
 rolesDeny(fn()=>$route->dispatch($http,$reviewCookie,
  ['role'=>'super_administrator'],$approvalCsrf),
  'invalid_staff_review_queue');
 rolesDeny(fn()=>$route->dispatch($http,$reviewCookie,[],
  str_repeat('0',64)),'staff_portal_denied');
 rolesDeny(fn()=>$route->dispatch(array_replace(
  $http,['HTTP_ORIGIN'=>'https://attacker.example']),
  $reviewCookie,[],$approvalCsrf),'staff_portal_denied');
 $decision=$decisionOn->decide($approverBearer,$id,$decisionId,
  'approved','Separate authenticated permission approval');
 rolesCheck($decision===['request_id'=>$id,'outcome'=>'approved',
  'grant_created'=>true],'independent current MFA approver records grant');
 $decisionHttp=$http;
 $decisionHttp['REQUEST_URI']='/staff/team/role-decisions';
 $decisionFields=['request_id'=>$id,'decision_id'=>$decisionId,
  'outcome'=>'approved','reason'=>'Separate authenticated permission approval'];
 $internalReceipt=$route->dispatch(
  $decisionHttp,$reviewCookie,$decisionFields,$approvalCsrf);
 rolesCheck($internalReceipt['kind']==='private_staff_role_decision_html' &&
  str_contains($internalReceipt['html'],'request approved') &&
  !str_contains($internalReceipt['html'],$approverBearer) &&
  !str_contains($internalReceipt['html'],$staff['target']) &&
  !str_contains($internalReceipt['html'],$approvalCsrf),
  'HTTP route replays exact authorized second decision with safe confirmation');
 $receipt=(new CentralStaffPortalPrivateResponse(true))->compose($internalReceipt);
 rolesCheck($receipt['cookies']===[] &&
  $receipt['headers']['Cache-Control']==='no-store, private',
  'approved role receipt contains no bearer and cannot be cached');
 rolesDeny(fn()=>$route->dispatch($decisionHttp,$reviewCookie,
  $decisionFields+['staff_id'=>$staff['super']],$approvalCsrf),
  'invalid_staff_role_decision');
 rolesDeny(fn()=>$route->dispatch($decisionHttp,$reviewCookie,
  $decisionFields,str_repeat('0',64)),
  'staff_portal_denied');
 rolesDeny(fn()=>$route->dispatch(array_replace($decisionHttp,[
  'HTTP_ORIGIN'=>'https://attacker.example']),
  $reviewCookie,$decisionFields,$approvalCsrf),
  'staff_portal_denied');
 $requesterCookie=[CentralStaffHttpPolicy::COOKIE_NAME=>$adminBearer];
 rolesDeny(fn()=>$route->dispatch($decisionHttp,$requesterCookie,
  $decisionFields,$requesterCsrf),'staff_role_decision_denied');

 rolesCheck($decisionOn->decide($approverBearer,$id,$decisionId,
  'approved','Separate authenticated permission approval')===$decision,
  'exact approved decision replay is idempotent');
 rolesCheck(!in_array($id,array_column(
  $queue->listPending($approverBearer),'request_id'),true),
  'approved role disappears from independent review queue');

 rolesDeny(fn()=>$decisionOn->decide($approverBearer,$id,rolesUuid(),
  'approved','Different duplicate approval'),
  'staff_role_decision_conflict');
 $query=$db->prepare("SELECT role,granted_by,approved_by,customer_scope_id
  FROM central_staff_role_grants WHERE staff_id=:target");
 $query->execute(['target'=>$staff['target']]);
 $assigned=$query->fetchAll(PDO::FETCH_ASSOC);
 rolesCheck(count($assigned)===1 &&
  $assigned[0]['role']==='support_agent' &&
  $assigned[0]['granted_by']===$staff['admin'] &&
  $assigned[0]['approved_by']===$staff['approver'],
  'approved grant tracks two independent real MFA staff identities');
 rolesCheck(!$access->permits($staff['target'],'support.reply',null,true),
  'approved grant cannot activate pending staff before identity and MFA enrollment');
 $rejected=$decisionOn->decide($approverBearer,$rejectIntent['request_id'],
  rolesUuid(),'rejected','Reviewer rejected proposed unnecessary role');
 rolesCheck($rejected['outcome']==='rejected' &&
  $rejected['grant_created']===false,'independent denial creates no role');
 $q=$db->prepare("SELECT count(*) FROM central_staff_role_grants
  WHERE staff_id=:target AND role='plan_administrator'");
 $q->execute(['target'=>$staff['target']]);
 rolesCheck((int)$q->fetchColumn()===0,'rejected role never granted');
 $q=$db->prepare("SELECT id FROM central_staff_role_decisions
  WHERE request_id=:request");
 $q->execute(['request'=>$id]);$sealedId=$q->fetchColumn();
 try{
  $db->prepare("UPDATE central_staff_role_decisions
    SET reason=reason WHERE id=:id")->execute(['id'=>$sealedId]);
  throw new RuntimeException('STAFF DECISION TAMPERED');
 } catch(PDOException $e){
  rolesCheck($e->getCode()==='23514',
   'immutable database role decisions reject tampering');
 }

 $db->prepare("INSERT INTO central_staff_role_grants
  (id,staff_id,role,reason)
  VALUES(:id,:target,'billing_officer','Synthetic segregation test')")
  ->execute(['id'=>rolesUuid(),'target'=>$staff['target']]);
 rolesDeny(fn()=>$on->request($adminBearer,rolesUuid(),$staff['target'],
  'license_officer',null,'Forbidden mixed payment and license duties'),
  'staff_role_request_denied');
 try{
  $db->prepare("DELETE FROM central_staff_role_requests WHERE id=:id")
   ->execute(['id'=>$id]);
  throw new RuntimeException('IMMUTABLE ROLE REQUEST WAS DELETED');
 }catch(PDOException $e){
  rolesCheck($e->getCode()==='23514',
   'database forbids removal of role request intent');
 }
 $db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
  WHERE staff_id=:admin")->execute(['admin'=>$staff['admin']]);
 rolesDeny(fn()=>$on->request($adminBearer,rolesUuid(),$staff['target'],
  'support_agent',null,'Revoked admin cannot request roles'),
  'staff_role_request_denied');
 rolesDeny(fn()=>$decisionOn->decide($approverBearer,
  $futureIntent['request_id'],rolesUuid(),'approved',
  'Revoked requester can no longer authorize grants'),
  'staff_role_decision_denied');
 $rejectStale=$decisionOn->decide($approverBearer,
  $futureIntent['request_id'],rolesUuid(),'rejected',
  'Independent rejection of stale staff request');
 rolesCheck($rejectStale['outcome']==='rejected' &&
  !$rejectStale['grant_created'],
  'reviewer can safely reject stale request even after issuer role revocation');

}finally{
 putenv('CENTRAL_STAFF_ROLE_REQUEST_ENABLED=false');
 putenv('CENTRAL_STAFF_ROLE_DECISIONS_ENABLED=false');
}
echo "PASS: MFA staff role requests are pending-only, independently gated, immutable, idempotent, never immediate grants\n";
