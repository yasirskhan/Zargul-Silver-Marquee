<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffTotpVerifier;

require dirname(__DIR__).'/src/CentralStaffTotpVerifier.php';

function totpAssert(bool $condition,string $label):void {
    if(!$condition)throw new RuntimeException('FAIL central staff TOTP '.$label);
}
$secret='12345678901234567890'; // Public RFC 6238 SHA-1 test secret; never deployed.
foreach([
    [59,'287082'],[1111111109,'081804'],[1111111111,'050471'],
    [1234567890,'005924'],[2000000000,'279037'],[20000000000,'353130'],
] as [$time,$code]){
    $counter=intdiv($time,30);
    totpAssert(CentralStaffTotpVerifier::matchCounter($secret,$code,$time,$counter-1)===$counter,
        'RFC 6238 SHA-1 6-digit suffix for '.$time);
    totpAssert(CentralStaffTotpVerifier::matchCounter($secret,$code,$time,$counter)===null,
        'one-time replay rejected for '.$time);
}
totpAssert(CentralStaffTotpVerifier::matchCounter($secret,'287082',89,-1)===1,
    'accept one prior interval under configured one-step skew');
totpAssert(CentralStaffTotpVerifier::matchCounter($secret,'287082',90,-1)===null,
    'reject code outside one-step clock window');
totpAssert(CentralStaffTotpVerifier::matchCounter($secret,'287082',59,1)===null,
    'already accepted window not usable twice');
foreach(['','123','A87082','0000000'] as $bad){
    totpAssert(CentralStaffTotpVerifier::matchCounter($secret,$bad,59,-1)===null,
        'malformed code rejected');
}
totpAssert(CentralStaffTotpVerifier::matchCounter('short','287082',59,-1)===null,
    'weak secret rejected');
totpAssert(CentralStaffTotpVerifier::matchCounter($secret,'287082',-1,-1)===null,
    'negative time rejected');
echo "PASS: RFC 6238 central staff TOTP vectors, window, replay and malformed input guards\n";
