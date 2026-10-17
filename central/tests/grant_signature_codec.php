<?php
declare(strict_types=1);

use Hafzatech\Signing\Ed25519GrantCodec;

require dirname(__DIR__).'/signing/Ed25519GrantCodec.php';

function checkSignature(bool $ok,string $label): void {
    if(!$ok) throw new RuntimeException('FAIL isolated signer codec '.$label);
}
$p=sodium_crypto_sign_keypair();
$grant=[
    'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
    'license_id'=>'e7e8e18a-6bbf-4b88-8a49-5876c0c0ff00',
    'customer_id'=>'b86bdadc-afbd-4ffd-823d-bcd47d0d06a7',
    'installation_id'=>'c6815111-7ea3-4345-b513-01cbfda55412',
    'revision'=>1,'term_months'=>6,'edition'=>'standard',
    'approved_domains'=>['testvenue.example.test'],
    'not_before'=>'2026-10-17T00:00:00Z',
    'expires_at'=>'2027-04-17T00:00:00Z',
    'limits'=>['max_halls'=>2,'max_staff'=>12],
    'features'=>['booking'=>true],
];
$envelope=Ed25519GrantCodec::sign($grant,'test-rotation-key',sodium_crypto_sign_secretkey($p));
$payload=base64_decode(strtr($envelope['payload'],'-_','+/'),true);
$signature=base64_decode(strtr($envelope['signature'],'-_','+/'),true);
checkSignature(sodium_crypto_sign_verify_detached($signature,
    'HAFZATECH-LICENSE-GRANT-V1'."\n".$payload,
    sodium_crypto_sign_publickey($p)),'Ed25519 detached signature');
checkSignature(!sodium_crypto_sign_verify_detached($signature,
    'HAFZATECH-LICENSE-GRANT-V1'."\n".str_replace('VenueDesk','AnotherProduct',$payload),
    sodium_crypto_sign_publickey($p)),'modified product never authentic');
try {
    Ed25519GrantCodec::sign($grant,'test',random_bytes(32));
    throw new RuntimeException('FAIL accepted wrong length private key');
} catch(InvalidArgumentException $expected) {}
$grant['term_months']=7;
try {
    Ed25519GrantCodec::sign($grant,'test',sodium_crypto_sign_secretkey($p));
    throw new RuntimeException('FAIL approved invalid license term');
} catch(InvalidArgumentException $expected) {}
echo "PASS: separate pure Ed25519 codec, grant schema, signature tampering and key length\n";
