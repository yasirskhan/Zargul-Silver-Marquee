<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffHttpPolicy;
use Hafzatech\Central\CentralStaffPortalResponseEmitter;
use Hafzatech\Central\RegistrationError;
require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/CentralStaffHttpPolicy.php';
require dirname(__DIR__).'/src/CentralStaffPortalResponseEmitter.php';

function emitCheck(bool $v,string $why):void {
    if(!$v) throw new RuntimeException('Protected vendor emitter: '.$why);
}
function emitDeny(callable $call,string $code):void {
    try {$call();} catch (RegistrationError $e) {
        emitCheck($e->errorCode===$code,
            'expected '.$code.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Protected vendor emitter: missing '.$code);
}
$headers=[];$cookies=[];$bodies=[];
$header=function(string $value)use(&$headers):void{$headers[]=$value;};
$cookie=function(string $name,string $value,array $options)use(&$cookies):bool{
    $cookies[]=[$name,$value,$options];
    return true;
};
$body=function(string $value)use(&$bodies):void{$bodies[]=$value;};
$writer=new CentralStaffPortalResponseEmitter(
    $header,$cookie,$body,static fn():bool=>false,true);
$disabled=new CentralStaffPortalResponseEmitter(
    $header,$cookie,$body,static fn():bool=>false);
$secret=str_repeat('a',64);
$csrf=str_repeat('b',64);
$plan=[
 'status'=>200,
 'headers'=>[
   'Content-Type'=>'text/html; charset=utf-8',
   'Cache-Control'=>'no-store, private',
   'X-Content-Type-Options'=>'nosniff',
   'Referrer-Policy'=>'no-referrer',
   'X-Frame-Options'=>'DENY',
   'Content-Security-Policy'=>
     "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
 ],
 'cookies'=>[[
   'name'=>CentralStaffHttpPolicy::COOKIE_NAME,
   'value'=>$secret,
   'options'=>[
      'expires'=>time()+3600,'path'=>'/',
      'secure'=>true,'httponly'=>true,'samesite'=>'Strict'
   ]
 ]],
 'body'=>'<!doctype html><html><head><meta name="staff-csrf-token" content="'.
     $csrf.'"></head><body>Private HAFZATECH staff</body></html>'
];
putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=false');
emitDeny(fn()=>$disabled->emit($plan),'staff_emitter_not_enabled');
emitDeny(fn()=>$writer->emit($plan),'staff_emitter_not_enabled');
emitCheck($headers===[] && $cookies===[] && $bodies===[],
 'both default gates OFF prevent output');
putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=true');
try {
    emitDeny(fn()=>$disabled->emit($plan),'staff_emitter_not_enabled');
    $writer->emit($plan);
    emitCheck(count($headers)===6 && count($cookies)===1 &&
        count($bodies)===1,'valid private response writes only required values');
    emitCheck($cookies[0][0]===CentralStaffHttpPolicy::COOKIE_NAME &&
        $cookies[0][2]['secure']===true &&
        $cookies[0][2]['httponly']===true &&
        $cookies[0][2]['samesite']==='Strict' &&
        str_contains($bodies[0],$csrf) &&
        !str_contains($bodies[0],$secret) &&
        !str_contains(implode(' ',$headers),$secret),
        'bearer only in host-only secure cookie; CSRF separate from it');
    $prior=[count($headers),count($cookies),count($bodies)];
    emitDeny(fn()=>$writer->emit(
        array_replace($plan,['status'=>302])),'invalid_staff_emission');
    emitDeny(fn()=>$writer->emit(array_replace($plan,[
        'headers'=>array_replace($plan['headers'],['Cache-Control'=>'public'])])),
        'invalid_staff_emission');
    emitDeny(fn()=>$writer->emit(array_replace($plan,[
        'headers'=>array_replace($plan['headers'],['Set-Cookie'=>'forged'])])),
        'invalid_staff_emission');
    emitDeny(fn()=>$writer->emit(array_replace($plan,[
        'cookies'=>[array_replace($plan['cookies'][0],[
          'options'=>array_replace($plan['cookies'][0]['options'],[
              'secure'=>false])])]])),'invalid_staff_emission');
    emitDeny(fn()=>$writer->emit(array_replace($plan,[
        'body'=>$plan['body'].$secret])),'invalid_staff_emission');
    emitDeny(fn()=>$writer->emit(array_replace($plan,[
        'cookies'=>[
           $plan['cookies'][0],$plan['cookies'][0]]])),'invalid_staff_emission');
    emitDeny(fn()=>$writer->emit(array_replace($plan,[
        'cookies'=>[array_replace($plan['cookies'][0],[
           'options'=>array_replace($plan['cookies'][0]['options'],[
               'domain'=>'attacker.example'])])]])),'invalid_staff_emission');
    $alreadySent=new CentralStaffPortalResponseEmitter(
        $header,$cookie,$body,static fn():bool=>true,true);
    emitDeny(fn()=>$alreadySent->emit($plan),'invalid_staff_emission');
    emitCheck([count($headers),count($cookies),count($bodies)]===$prior,
        'invalid responses produce no partial output');
    $loggedOut=array_replace($plan,[
        'cookies'=>[array_replace($plan['cookies'][0],[
            'value'=>'',
            'options'=>array_replace($plan['cookies'][0]['options'],[
                'expires'=>1])])],
        'body'=>'<!doctype html><html><body>Signed out</body></html>'
    ]);
    $writer->emit($loggedOut);
    emitCheck($cookies[1][1]==='' &&
        $cookies[1][2]['expires']===1,'logout secure cookie clearing');
    $failing=new CentralStaffPortalResponseEmitter(
        $header,static fn(string $n,string $v,array $o):bool=>false,
        $body,static fn():bool=>false,true);
    $priorBodies=count($bodies);
    emitDeny(fn()=>$failing->emit($plan),'staff_cookie_write_failed');
    emitCheck(count($bodies)===$priorBodies,
        'cookie failure never outputs protected HTML body');
} finally {
    putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=false');
}
echo "PASS: default-OFF operator response emitter, strict cookie attributes, no bearer leak, deny before output\n";
