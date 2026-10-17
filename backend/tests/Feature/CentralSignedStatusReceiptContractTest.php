<?php

namespace Tests\Feature;

use App\Services\CentralSignedStatusReceiptVerifier;
use DateTimeImmutable;
use Hafzatech\Signing\Ed25519StatusReceiptCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/central/signing/Ed25519StatusReceiptCodec.php';

/**
 * Contract parity: vendor signer and VenueDesk verifier MUST agree on exact
 * signature context, encoded fields and trust pinning. No live service or key.
 */
class CentralSignedStatusReceiptContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_isolated_vendor_codec_interoperates_with_client_pinned_verifier(): void
    {
        $pair=sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys', [
            'synthetic-vendor'=>rtrim(strtr(
                base64_encode(sodium_crypto_sign_publickey($pair)), '+/', '-_'), '='),
        ]);
        $nonce=rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $payload=[
            'api_version'=>'v1','issuer'=>'HAFZATECH',
            'installation_id'=>'c6815111-7ea3-4345-b513-01cbfda55412',
            'customer_id'=>'b86bdadc-afbd-4ffd-823d-bcd47d0d06a7',
            'nonce'=>$nonce,
            'status'=>'signed_revision_pending_delivery',
            'revision'=>12,
            'issued_at'=>'2026-10-17T23:30:00Z',
        ];
        $envelope=Ed25519StatusReceiptCodec::sign(
            $payload,'synthetic-vendor',sodium_crypto_sign_secretkey($pair)
        );
        $verified=app(CentralSignedStatusReceiptVerifier::class)->verify(
            $envelope,$payload['installation_id'],$payload['customer_id'],$nonce,
            new DateTimeImmutable('2026-10-17T23:30:01Z')
        );
        $this->assertSame(12,$verified['revision']);
        $this->assertSame('2026-10-17T23:30:00Z',$verified['verified_at']);
        $this->assertSame('signed_revision_pending_delivery',$verified['status']);

        $this->expectException(InvalidArgumentException::class);
        app(CentralSignedStatusReceiptVerifier::class)->verify(
            $envelope,$payload['installation_id'],$payload['customer_id'],
            rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='),
            new DateTimeImmutable('2026-10-17T23:30:01Z')
        );
    }
}
