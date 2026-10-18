<?php
declare(strict_types=1);

use Hafzatech\Central\CentralInstallationApiHttpPolicy;
require dirname(__DIR__).'/src/CentralInstallationApiHttpPolicy.php';
function centralApiPolicyAssert(bool $condition,string $why):void{
    if(!$condition)throw new RuntimeException('Central installation HTTP host: '.$why);
}
$valid=[
 'HTTP_HOST'=>'lichall.hafzatech.com',
 'HTTPS'=>'on',
 'SERVER_PORT'=>'443',
 'REQUEST_METHOD'=>'POST',
 'REQUEST_URI'=>'/api/v1/installations/status'
];
centralApiPolicyAssert(CentralInstallationApiHttpPolicy::trustedHost($valid),
    'canonical central HTTPS host accepted');
foreach ([
 ['HTTP_HOST'=>'api.attacker.example'],
 ['HTTP_HOST'=>'lichall.hafzatech.com.attacker.example'],
 ['HTTP_HOST'=>'lichall.hafzatech.com:443'],
 ['HTTP_HOST'=>'LICHALL.HAFZATECH.COM'],
 ['HTTP_HOST'=>"lichall.hafzatech.com\nX-Other: yes"],
 ['HTTP_HOST'=>''],
 ['HTTP_HOST'=>null],
 ['HTTPS'=>'off'],
 ['HTTPS'=>'0'],
 ['HTTPS'=>''],
] as $change) {
 $bad=array_replace($valid,$change);
 centralApiPolicyAssert(!CentralInstallationApiHttpPolicy::trustedHost($bad),
    'reject forged host, port and plaintext server flags: '.json_encode($change));
}
$spoof=[
 'HTTP_HOST'=>'lichall.hafzatech.com',
 'SERVER_PORT'=>'80',
 'HTTP_X_FORWARDED_PROTO'=>'https',
 'HTTP_FORWARDED'=>'proto=https',
 'HTTP_X_FORWARDED_HOST'=>'lichall.hafzatech.com'
];
centralApiPolicyAssert(!CentralInstallationApiHttpPolicy::trustedHost($spoof),
 'untrusted forwarded TLS and hostname claims do not enable vendor API');
$portOnly=$valid;unset($portOnly['HTTPS']);
centralApiPolicyAssert(CentralInstallationApiHttpPolicy::trustedHost($portOnly),
 'trusted server port 443 fallback preserved without contradictory HTTPS=off');
$portOnly['HTTPS']='off';
centralApiPolicyAssert(!CentralInstallationApiHttpPolicy::trustedHost($portOnly),
 'explicit plaintext rejects even when port metadata suggests 443');
echo "PASS: default-OFF central registration/status endpoints require exact approved vendor Host plus trusted HTTPS origin\n";
