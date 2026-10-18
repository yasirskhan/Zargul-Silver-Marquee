<?php
declare(strict_types=1);
require dirname(__DIR__).'/signing/Ed25519SupportReplyPageCodec.php';
use Hafzatech\Signing\Ed25519SupportReplyPageCodec;
$pair=sodium_crypto_sign_keypair();
$c='c6815111-7ea3-4345-b513-01cbfda55412';
$i='b86bdadc-afbd-4ffd-823d-bcd47d0d06a7';
$n=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$p=['api_version'=>'v1','next_cursor'=>7,'messages'=>[['event_id'=>7,'body'=>'Test agent reply']]];
$e=Ed25519SupportReplyPageCodec::sign($p,$c,$i,$n,0,'test',sodium_crypto_sign_secretkey($pair));
$raw=base64_decode(strtr($e['payload'],'-_','+/'),true);
$sig=base64_decode(strtr($e['signature'],'-_','+/'),true);
if(!sodium_crypto_sign_verify_detached($sig,'HAFZATECH-SUPPORT-REPLY-PAGE-V1'."\n".$raw,sodium_crypto_sign_publickey($pair)))
    throw new RuntimeException('Vendor support signature mismatch');
foreach([
    ['api_version'=>'v1','next_cursor'=>6,'messages'=>$p['messages']],
    ['api_version'=>'v1','next_cursor'=>7,'messages'=>[['event_id'=>0,'body'=>'forged']]],
] as $bad){
    try{Ed25519SupportReplyPageCodec::sign($bad,$c,$i,$n,0,'test',sodium_crypto_sign_secretkey($pair));
        throw new RuntimeException('Unexpected malformed page acceptance');
    }catch(InvalidArgumentException $expected){}
}
echo "PASS: vendor support reply page signing, scope and cursor guards\n";
