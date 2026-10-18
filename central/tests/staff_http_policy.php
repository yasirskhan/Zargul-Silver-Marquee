<?php
declare(strict_types=1);
use Hafzatech\Central\CentralStaffHttpPolicy;
require dirname(__DIR__).'/src/CentralStaffHttpPolicy.php';
function httpCheck(bool $ok,string $description):void {
    if(!$ok)throw new RuntimeException('Central staff HTTP policy: '.$description);
}
$valid=[
    'HTTPS'=>'on','SERVER_PORT'=>'443',
    'HTTP_HOST'=>'lichall.hafzatech.com',
    'REQUEST_METHOD'=>'POST',
    'REQUEST_URI'=>'/staff/session/logout',
    'HTTP_ORIGIN'=>CentralStaffHttpPolicy::ORIGIN,
    'HTTP_SEC_FETCH_SITE'=>'same-origin',
    'REMOTE_ADDR'=>'192.0.2.7'
];
httpCheck(CentralStaffHttpPolicy::staffMutationAllowed($valid),'same origin HTTPS staff POST permitted');
foreach([
    ['HTTPS'=>'off','SERVER_PORT'=>'80','HTTP_X_FORWARDED_PROTO'=>'https'],
    ['HTTP_HOST'=>'evil.example'],
    ['HTTP_HOST'=>'lichall.hafzatech.com.evil.example'],
    ['REQUEST_METHOD'=>'GET'],
    ['REQUEST_URI'=>'/customer/account'],
    ['REQUEST_URI'=>'/staffing/unrelated'],
    ['HTTP_ORIGIN'=>'https://evil.example'],
    ['HTTP_ORIGIN'=>'https://lichall.hafzatech.com.evil.example'],
    ['HTTP_SEC_FETCH_SITE'=>'cross-site']
] as $mutation){
    $candidate=array_replace($valid,$mutation);
    httpCheck(!CentralStaffHttpPolicy::staffMutationAllowed($candidate),
        'spoofed or cross-boundary request denied: '.json_encode($mutation));
}
$missing=$valid;unset($missing['HTTP_ORIGIN']);
httpCheck(!CentralStaffHttpPolicy::staffMutationAllowed($missing),'missing Origin fails closed');
$forward=$valid;$forward['REMOTE_ADDR']='192.0.2.7';
$forward['HTTP_X_FORWARDED_FOR']='198.51.100.88';
httpCheck(CentralStaffHttpPolicy::directPeerIp($forward)==='192.0.2.7',
    'untrusted forwarded IP must not replace transport peer');
httpCheck(CentralStaffHttpPolicy::directPeerIp(['REMOTE_ADDR'=>'invalid'])===null,
    'invalid direct peer cannot enter vendor login throttling');
$cookie=CentralStaffHttpPolicy::cookieOptions(100000);
httpCheck(str_starts_with(CentralStaffHttpPolicy::COOKIE_NAME,'__Host-') &&
    $cookie['path']==='/' && $cookie['secure']===true &&
    $cookie['httponly']===true && $cookie['samesite']==='Strict' &&
    $cookie['expires']===143200 && !array_key_exists('domain',$cookie),
    'host-only HttpOnly Secure Strict vendor session cookie');
echo "PASS: central staff trusted HTTPS origin, mutation path/method, safe cookie and direct peer policy\n";
