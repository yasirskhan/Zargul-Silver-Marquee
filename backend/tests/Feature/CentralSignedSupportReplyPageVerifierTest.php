<?php

namespace Tests\Feature;

use App\Services\CentralSignedSupportReplyPageVerifier;
use Hafzatech\Signing\Ed25519SupportReplyPageCodec;
use InvalidArgumentException;
use Tests\TestCase;

require_once dirname(__DIR__,3).'/central/signing/Ed25519SupportReplyPageCodec.php';

class CentralSignedSupportReplyPageVerifierTest extends TestCase
{
    public function test_vendor_signed_support_page_requires_exact_tenant_nonce_and_cursor():void
    {
        $pair=sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys',[
            'synthetic-support'=>rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($pair)),'+/','-_'),'='),
        ]);
        $customer='c6815111-7ea3-4345-b513-01cbfda55412';
        $install='b86bdadc-afbd-4ffd-823d-bcd47d0d06a7';
        $nonce=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $page=['api_version'=>'v1','next_cursor'=>10,'messages'=>[
            ['event_id'=>10,'body'=>'Agent answer.','client_ticket_id'=>'request-1','message_id'=>'reply-1'],
        ]];
        $e=Ed25519SupportReplyPageCodec::sign($page,$customer,$install,$nonce,0,
            'synthetic-support',sodium_crypto_sign_secretkey($pair));
        $verifier=app(CentralSignedSupportReplyPageVerifier::class);
        $out=$verifier->verify($e,$customer,$install,$nonce,0);
        $this->assertSame(10,$out['next_cursor']);
        $this->assertCount(1,$out['messages']);
        foreach([
            [$customer,$install,'wrong-nonce',0],
            [$customer,$install,$nonce,1],
            [$install,$customer,$nonce,0],
        ] as [$c,$i,$n,$cursor]){
            try{$verifier->verify($e,$c,$i,$n,$cursor);$this->fail('Untrusted page accepted.');}
            catch(InvalidArgumentException $expected){}
        }
        $tampered=$e;$tampered['payload']=rtrim(strtr(base64_encode('forged'),'+/','-_'),'=');
        $this->expectException(InvalidArgumentException::class);
        $verifier->verify($tampered,$customer,$install,$nonce,0);
    }

    public function test_no_trusted_signing_keys_cannot_accept_pages():void
    {
        config()->set('central_control.trusted_signing_public_keys',[]);
        $this->expectException(InvalidArgumentException::class);
        app(CentralSignedSupportReplyPageVerifier::class)->verify([
            'key_id'=>'fake','payload'=>'Zm9v','signature'=>'Zm9v'
        ],'customer','installation','nonce',0);
    }
}
