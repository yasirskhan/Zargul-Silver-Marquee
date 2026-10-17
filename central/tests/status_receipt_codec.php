<?php
declare(strict_types=1);

use Hafzatech\Signing\Ed25519StatusReceiptCodec;

require dirname(__DIR__).'/signing/Ed25519StatusReceiptCodec.php';

function receiptAssert(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('FAIL receipt signing codec '.$label);
}
$pair=sodium_crypto_sign_keypair();
$payload=[
    'api_version'=>'v1','issuer'=>'HAFZATECH',
    'installation_id'=>'c6815111-7ea3-4345-b513-01cbfda55412',
    'customer_id'=>'b86bdadc-afbd-4ffd-823d-bcd47d0d06a7',
    'nonce'=>rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='),
    'status'=>'signed_revision_pending_delivery',
    'revision'=>3,'issued_at'=>gmdate('Y-m-d\TH:i:s\Z'),
];
$envelope=Ed25519StatusReceiptCodec::sign($payload,'synthetic-key',
    sodium_crypto_sign_secretkey($pair));
$bytes=base64_decode(strtr($envelope['payload'],'-_','+/'),true);
$sig=base64_decode(strtr($envelope['signature'],'-_','+/'),true);
receiptAssert(sodium_crypto_sign_verify_detached($sig,
    'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".$bytes,
    sodium_crypto_sign_publickey($pair)),'versioned Ed25519 verification');
receiptAssert(!sodium_crypto_sign_verify_detached($sig,
    'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".str_replace('"revision":3','"revision":4',$bytes),
    sodium_crypto_sign_publickey($pair)),'revision tampering');
try {
    Ed25519StatusReceiptCodec::sign($payload,'test',random_bytes(32));
    throw new RuntimeException('FAIL signer accepted short private key');
} catch(InvalidArgumentException $expected) {}
$payload['status']='registered_pending_license';
try {
    Ed25519StatusReceiptCodec::sign($payload,'test',sodium_crypto_sign_secretkey($pair));
    throw new RuntimeException('FAIL inconsistent revision semantics');
} catch(InvalidArgumentException $expected) {}
// A revoked never-licensed installation may have zero signed revisions.
$payload['status']='revoked';
$payload['revision']=0;
$revoke=Ed25519StatusReceiptCodec::sign($payload,'test',sodium_crypto_sign_secretkey($pair));
receiptAssert(isset($revoke['signature']),'revocation with no paid revision can be authenticated');
echo "PASS: isolated vendor status receipt primitive, strict fields and signature authenticity\n";
