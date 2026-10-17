<?php

namespace Tests\Feature;

use App\Services\CentralSignedStatusReceiptVerifier;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class CentralSignedStatusReceiptVerifierTest extends TestCase
{
    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
    }

    public function test_pinned_vendor_receipt_authenticates_customer_installation_nonce_and_time(): void
    {
        $pair=sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys', [
            'test-receipt-key'=>self::b64(sodium_crypto_sign_publickey($pair)),
        ]);
        $data=[
            'api_version'=>'v1', 'issuer'=>'HAFZATECH',
            'installation_id'=>'c6815111-7ea3-4345-b513-01cbfda55412',
            'customer_id'=>'b86bdadc-afbd-4ffd-823d-bcd47d0d06a7',
            'nonce'=>self::b64(random_bytes(32)),
            'status'=>'signed_revision_pending_delivery',
            'revision'=>2, 'issued_at'=>'2026-10-17T23:00:00Z',
        ];
        $raw=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $sign=fn(string $value)=>self::b64(sodium_crypto_sign_detached(
            'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".$value,
            sodium_crypto_sign_secretkey($pair)));
        $envelope=['key_id'=>'test-receipt-key','payload'=>self::b64($raw),
            'signature'=>$sign($raw)];
        $checker=app(CentralSignedStatusReceiptVerifier::class);
        $now=new DateTimeImmutable('2026-10-17T23:00:01Z');
        $result=$checker->verify($envelope,$data['installation_id'],$data['customer_id'],
            $data['nonce'],$now);
        $this->assertSame(2,$result['revision']);
        $this->assertSame('signed_revision_pending_delivery',$result['status']);

        foreach ([
            [$envelope,$data['installation_id'],$data['customer_id'],self::b64(random_bytes(32)),$now],
            [$envelope,$data['installation_id'],'11111111-1111-4111-8111-111111111111',$data['nonce'],$now],
            [$envelope,$data['installation_id'],$data['customer_id'],$data['nonce'],
                new DateTimeImmutable('2026-10-17T23:06:00Z')],
        ] as [$candidate,$installation,$customer,$nonce,$clock]) {
            try {
                $checker->verify($candidate,$installation,$customer,$nonce,$clock);
                $this->fail('Foreign, replayed or expired vendor status accepted.');
            } catch (InvalidArgumentException) {}
        }
        $changed=$envelope;
        $changed['payload']=self::b64(str_replace('"revision":2','"revision":3',$raw));
        $this->expectException(InvalidArgumentException::class);
        $checker->verify($changed,$data['installation_id'],$data['customer_id'],$data['nonce'],$now);
    }

    public function test_unpinned_status_signer_is_always_denied(): void
    {
        config()->set('central_control.trusted_signing_public_keys', []);
        $this->expectException(InvalidArgumentException::class);
        app(CentralSignedStatusReceiptVerifier::class)->verify([
            'key_id'=>'unknown','payload'=>self::b64('{}'),
            'signature'=>self::b64(random_bytes(64)),
        ],'any','any','any',new DateTimeImmutable('now'));
    }
}
