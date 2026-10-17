<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use App\Services\CentralInstallationStatusTransport;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CentralInstallationStatusTransportTest extends TestCase
{
    use RefreshDatabase;

    private function enrolled(): array
    {
        config()->set('central_control.activation_enabled', true);
        $local = app(CentralInstallationIdentity::class)->initialize();
        $customer = (string) Str::uuid();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1, 'installation_id'=>$local['installation_id'],
            'customer_id'=>$customer, 'approved_domain'=>'test.example.test',
            'status'=>'registered_pending_license', 'central_registered_at'=>now(),
            'created_at'=>now(), 'updated_at'=>now(),
        ]);
        return [$local, $customer];
    }

    public function test_disabled_or_unenrolled_installation_never_calls_vendor(): void
    {
        Http::preventStrayRequests();
        $this->expectException(AuthorizationException::class);
        app(CentralInstallationStatusTransport::class)->check(new DateTimeImmutable('now'));
    }

    public function test_signed_request_nonce_and_fixed_vendor_host_without_claiming_licensing(): void
    {
        [$local, $customer] = $this->enrolled();
        $proofIsValid = false;
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status' =>
            function (Request $request) use ($local, $customer, &$proofIsValid) {
                $body = $request->data();
                $this->assertSame($customer, $body['customer_id']);
                $this->assertSame($local['installation_id'], $body['installation_id']);
                $this->assertSame(0, $body['known_revision']);
                $msg = implode("\n", [
                    'HAFZATECH-INSTALL-CHECK-V1', $local['installation_id'], $customer,
                    $body['request_time'], $body['nonce'], '0',
                ]);
                $proofIsValid = sodium_crypto_sign_verify_detached(
                    base64_decode(strtr($body['signature'], '-_', '+/'), true),
                    $msg, base64_decode(strtr($local['public_key'], '-_', '+/'), true)
                );
                return Http::response([
                    'api_version'=>'v1','status'=>'registered_pending_license',
                    'registered_installation_id'=>$local['installation_id'],
                    'nonce'=>$body['nonce'],'revision'=>0,
                ], 200);
            },
        ]);
        $out = app(CentralInstallationStatusTransport::class)
            ->check(new DateTimeImmutable('2026-10-17T22:00:00Z'));
        $this->assertTrue($proofIsValid);
        $this->assertSame(['status'=>'registered_pending_license',
            'revision_available'=>0, 'licensed'=>false], $out);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('central_license_check_state', 0);
        $this->assertDatabaseCount('central_signed_license_cache', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_wrong_response_nonce_is_not_central_verified_or_license_issued(): void
    {
        [$local] = $this->enrolled();
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status' =>
            Http::response([
                'api_version'=>'v1','status'=>'signed_revision_pending_delivery',
                'registered_installation_id'=>$local['installation_id'],
                'nonce'=>'not-the-signed-nonce','revision'=>55,
            ], 200),
        ]);
        $this->expectException(InvalidArgumentException::class);
        try {
            app(CentralInstallationStatusTransport::class)
                ->check(new DateTimeImmutable('now'));
        } finally {
            $this->assertDatabaseCount('central_license_check_state', 0);
            $this->assertDatabaseCount('central_signed_license_cache', 0);
        }
    }

    public function test_optional_signed_receipt_must_match_vendor_key_identity_nonce_and_report(): void
    {
        [$local, $customer] = $this->enrolled();
        $pair = sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys', [
            'status-test' => self::encode(sodium_crypto_sign_publickey($pair)),
        ]);
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status' =>
            function (Request $request) use ($local, $customer, $pair) {
                $nonce=$request['nonce'];
                $data=[
                    'api_version'=>'v1', 'issuer'=>'HAFZATECH',
                    'installation_id'=>$local['installation_id'],
                    'customer_id'=>$customer,
                    'nonce'=>$nonce, 'status'=>'signed_revision_pending_delivery',
                    'revision'=>4, 'issued_at'=>gmdate('Y-m-d\TH:i:s\Z'),
                ];
                $raw=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                return Http::response([
                    'api_version'=>'v1', 'registered_installation_id'=>$local['installation_id'],
                    'nonce'=>$nonce, 'status'=>'signed_revision_pending_delivery',
                    'revision'=>4,
                    'signed_receipt'=>[
                        'key_id'=>'status-test',
                        'payload'=>self::encode($raw),
                        'signature'=>self::encode(sodium_crypto_sign_detached(
                            'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".$raw,
                            sodium_crypto_sign_secretkey($pair))),
                    ],
                ],200);
            },
        ]);
        $result=app(CentralInstallationStatusTransport::class)->check(new DateTimeImmutable('now'));
        $this->assertTrue($result['vendor_signed']);
        $this->assertSame(4,$result['revision_available']);
        $this->assertFalse($result['licensed']);
        $this->assertDatabaseCount('central_signed_license_cache',0);
        $this->assertDatabaseCount('central_license_check_state',0);
    }

    public function test_conflicting_signed_receipt_never_authenticates_unsigned_status(): void
    {
        [$local, $customer] = $this->enrolled();
        $pair=sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys',[
            'status-test'=>self::encode(sodium_crypto_sign_publickey($pair)),
        ]);
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status' =>
            function(Request $request)use($local,$customer,$pair){
                $payload=[
                    'api_version'=>'v1','issuer'=>'HAFZATECH',
                    'installation_id'=>$local['installation_id'],
                    'customer_id'=>$customer,'nonce'=>$request['nonce'],
                    'status'=>'signed_revision_pending_delivery','revision'=>7,
                    'issued_at'=>gmdate('Y-m-d\TH:i:s\Z'),
                ];
                $raw=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                return Http::response([
                    'api_version'=>'v1','registered_installation_id'=>$local['installation_id'],
                    'nonce'=>$request['nonce'],
                    'status'=>'signed_revision_pending_delivery','revision'=>8,
                    'signed_receipt'=>[
                        'key_id'=>'status-test','payload'=>self::encode($raw),
                        'signature'=>self::encode(sodium_crypto_sign_detached(
                            'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".$raw,
                            sodium_crypto_sign_secretkey($pair))),
                    ],
                ],200);
            },
        ]);
        $this->expectException(InvalidArgumentException::class);
        try{
            app(CentralInstallationStatusTransport::class)->check(new DateTimeImmutable('now'));
        }finally{
            $this->assertDatabaseCount('central_license_check_state',0);
            $this->assertDatabaseCount('central_signed_license_cache',0);
        }
    }

    public function test_redirect_does_not_disclose_identity_to_untrusted_host(): void
    {
        $this->enrolled();
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status' =>
            Http::response('', 302, ['Location'=>'https://attacker.example.test']),
        ]);
        try {
            app(CentralInstallationStatusTransport::class)->check(new DateTimeImmutable('now'));
            $this->fail('Untrusted redirect accepted.');
        } catch (InvalidArgumentException) {}
        Http::assertSentCount(1);
        Http::assertNotSent(fn(Request $req) =>
            str_contains($req->url(), 'attacker.example.test'));
    }
}
