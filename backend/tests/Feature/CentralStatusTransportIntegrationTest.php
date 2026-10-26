<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use App\Services\CentralInstallationStatusTransport;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralStatusTransportIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function test_signed_http_status_delivers_grant_and_persists_license(): void
    {
        config()->set('central_control.activation_enabled', true);
        $identity = app(CentralInstallationIdentity::class)->initialize();
        $customer = (string) Str::uuid();
        $license = (string) Str::uuid();
        $domain = 'transport.example.test';
        $company = \App\Models\Company::create(['name' => 'Transport Venue',
            'slug' => 'transport-venue']);
        DB::table('central_installation_activation')->insert([
            'singleton_id' => 1, 'company_id' => $company->id,
            'installation_id' => $identity['installation_id'],
            'customer_id' => $customer, 'approved_domain' => $domain,
            'status' => 'registered_pending_license', 'central_registered_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pair = sodium_crypto_sign_keypair();
        $public = $this->b64(sodium_crypto_sign_publickey($pair));
        config()->set('central_control.trusted_signing_public_keys', [
            'transport-test' => $public,
        ]);
        $now = new DateTimeImmutable('2026-11-01T00:00:00Z');
        Http::fake(function ($request) use ($pair, $identity, $customer, $license, $domain, $now) {
            $nonce = $request['nonce'];
            $receiptClaim = [
                'api_version' => 'v1', 'issuer' => 'HAFZATECH',
                'installation_id' => $identity['installation_id'],
                'customer_id' => $customer, 'nonce' => $nonce,
                'status' => 'signed_revision_pending_delivery', 'revision' => 1,
                'issued_at' => $now->format('Y-m-d\TH:i:s\Z'),
            ];
            $receiptRaw = json_encode($receiptClaim, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $grant = [
                'api_version' => 'v1', 'issuer' => 'HAFZATECH', 'product' => 'VenueDesk',
                'license_id' => $license, 'installation_id' => $identity['installation_id'],
                'customer_id' => $customer, 'revision' => 1, 'term_months' => 12,
                'edition' => 'standard', 'approved_domains' => [$domain],
                'not_before' => '2026-10-01T00:00:00Z', 'expires_at' => '2027-10-01T00:00:00Z',
                'limits' => ['max_halls' => 3, 'max_staff' => 10],
                'features' => ['booking' => true],
            ];
            $grantRaw = json_encode($grant, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            return Http::response([
                'api_version' => 'v1',
                'registered_installation_id' => $identity['installation_id'],
                'nonce' => $nonce, 'status' => 'signed_revision_pending_delivery',
                'revision' => 1,
                'signed_receipt' => ['key_id' => 'transport-test',
                    'payload' => $this->b64($receiptRaw),
                    'signature' => $this->b64(sodium_crypto_sign_detached(
                        "HAFZATECH-INSTALL-STATUS-RECEIPT-V1\n".$receiptRaw,
                        sodium_crypto_sign_secretkey($pair)))],
                'signed_grant' => ['key_id' => 'transport-test',
                    'payload' => $this->b64($grantRaw),
                    'signature' => $this->b64(sodium_crypto_sign_detached(
                        "HAFZATECH-LICENSE-GRANT-V1\n".$grantRaw,
                        sodium_crypto_sign_secretkey($pair)))],
            ], 200);
        });

        $result = app(CentralInstallationStatusTransport::class)->check($now);
        $this->assertTrue($result['licensed']);
        $this->assertSame(1, $result['license_revision_accepted']);
        $this->assertSame(1, (int) DB::table('central_signed_license_cache')
            ->where('singleton_id', 1)->value('revision'));
        Http::assertSent(fn ($request): bool =>
            $request->url() === 'https://lichall.hafzatech.com/api/v1/installations/status'
            && $request['installation_id'] === $identity['installation_id']);
    }
}
