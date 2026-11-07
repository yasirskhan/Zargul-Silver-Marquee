<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffPortalOperatorWiring;
use Hafzatech\Central\RegistrationError;

spl_autoload_register(static function(string $class):void{
    $prefix='Hafzatech\\Central\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(preg_match('/^[A-Za-z0-9]+$/D',$name)!==1)return;
    $path=dirname(__DIR__).'/src/'.$name.'.php';
    if(is_file($path))require $path;
});
function readAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('Central read: '.$why);}
function readId():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
function readOtp(string $seed):string{$n=intdiv(time(),30);$mac=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),$seed,true);$at=ord($mac[19])&15;$v=unpack('N',substr($mac,$at,4))[1]&0x7fffffff;return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);}
$dsn=getenv('CENTRAL_DB_DSN');
readAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),'isolated DB required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$company=readId();$foreign=readId();$enrollment=readId();$installation=readId();
$db->prepare("INSERT INTO vendor_customers(id,legal_name,status) VALUES(:id,'Read Navigation Venue','active')")->execute(['id'=>$company]);
$db->prepare("INSERT INTO vendor_customers(id,legal_name,status) VALUES(:id,'Foreign Read Venue','active')")->execute(['id'=>$foreign]);
$db->prepare("INSERT INTO installation_enrollments(id,customer_id,token_sha256,approved_domain,expires_at) VALUES(:id,:c,:t,'read-nav.example.test',now()+interval '1 day')")->execute(['id'=>$enrollment,'c'=>$company,'t'=>hash('sha256',$enrollment)]);
$db->prepare("INSERT INTO registered_installations(id,customer_id,enrollment_id,approved_domain,ed25519_public_key,status) VALUES(:id,:c,:e,'read-nav.example.test',:k,'active')")->execute(['id'=>$installation,'c'=>$company,'e'=>$enrollment,'k'=>rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=')]);
$db->prepare("INSERT INTO central_licenses(id,customer_id,installation_id,approved_domain,current_revision) VALUES(:id,:c,:i,'read-nav.example.test',0)")->execute(['id'=>readId(),'c'=>$company,'i'=>$installation]);
$staff=readId();$email='read-nav-'.bin2hex(random_bytes(4)).'@example.test';$secret='read-secret-'.bin2hex(random_bytes(8));$password='read-navigation-password';
$db->prepare("INSERT INTO central_staff_users(id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status) VALUES(:id,:e,'Read Officer',:p,decode(:s,'hex'),now(),'active')")->execute(['id'=>$staff,'e'=>$email,'p'=>password_hash($password,PASSWORD_DEFAULT),'s'=>bin2hex($secret)]);
$db->prepare("INSERT INTO central_staff_role_grants(id,staff_id,role,customer_scope_id,reason) VALUES(:id,:s,'license_officer',:c,'Read route fixture')")->execute(['id'=>readId(),'s'=>$staff,'c'=>$company]);
putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=true');putenv('CENTRAL_STAFF_PORTAL_ENABLED=true');putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=true');
$headers=[];$cookies=[];$bodies=[];
$app=CentralStaffPortalOperatorWiring::build($db,str_repeat('r',32),static fn(string $v):string=>$v,static fn(string $v):string=>random_bytes(24).$v,static function(string $h)use(&$headers):void{$headers[]=$h;},static function(string $n,string $v,array $o)use(&$cookies):bool{$cookies[]=[$n,$v,$o];return true;},static function(string $b)use(&$bodies):void{$bodies[]=$b;},static fn():bool=>false,true);
$server=['HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'lichall.hafzatech.com','HTTP_ORIGIN'=>'https://lichall.hafzatech.com','HTTP_SEC_FETCH_SITE'=>'same-origin','REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'192.0.2.240','CONTENT_TYPE'=>'application/x-www-form-urlencoded'];
try{
    $app->serve(array_merge($server,['REQUEST_URI'=>'/staff/session/login']),[],http_build_query(['email'=>$email,'password'=>$password,'totp'=>readOtp($secret)]));
    readAssert(count($cookies)===1,'login cookie missing');
    $bag=['__Host-HafzatechStaff'=>$cookies[0][1]];
    $get=$server;$get['REQUEST_METHOD']='GET';
    $app->serve(array_merge($get,['REQUEST_URI'=>'/staff/home']),$bag,'');
    $home=end($bodies);readAssert(str_contains($home,'Central Control Center')&&str_contains($home,'Customers &amp; installations'),'home navigation missing');
    $app->serve(array_merge($get,['REQUEST_URI'=>'/staff/customers/directory']),$bag,'');
    $directory=end($bodies);readAssert(str_contains($directory,'Read Navigation Venue')&&!str_contains($directory,'Foreign Read Venue'),'directory scope leak');
    $app->serve(array_merge($get,['REQUEST_URI'=>'/staff/customers/'.$company]),$bag,'');
    $dashboard=end($bodies);readAssert(str_contains($dashboard,'read-nav.example.test')&&str_contains($dashboard,'Read Navigation Venue'),'customer workspace missing');
    try{$app->serve(array_merge($get,['REQUEST_URI'=>'/staff/customers/'.$foreign]),$bag,'');throw new RuntimeException('foreign workspace allowed');}catch(RegistrationError $e){readAssert($e->errorCode==='customer_scope_denied'||$e->errorCode==='staff_portal_denied','unexpected foreign denial');}
    $before=end($bodies);
    try{$app->serve(array_merge($get,['REQUEST_URI'=>'/staff/session/logout']),$bag,'');throw new RuntimeException('GET mutation allowed');}catch(RegistrationError){}
    readAssert(end($bodies)===$before,'GET changed state');
} finally {
    putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=false');putenv('CENTRAL_STAFF_PORTAL_ENABLED=false');putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=false');
}
echo "PASS: authenticated Central GET navigation, scoped customer workspace, foreign denial and no GET mutation\n";