<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use App\Services\CentralVerifiedLicenseDeliveryService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralLicenseDeliveryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function receipt(string $pair, string $installation, string $customer,
        string $nonce, int $revision, string $issuedAt): array
    {
        $claim = [
            'api_version' => 'v1', 'issuer' => 'HAFZATECH',
            'installation_id' => $installation, 'customer_id' => $customer,
            'nonce' => $nonce, 'status' => 'signed_revision_pending_delivery',
            'revision' => $revision, 'issued_at' => $issuedAt,
        ];
        $raw = json_encode($claim, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['key_id' => 'delivery-test', 'payload' => $this->b64($raw),
            'signature' => $this->b64(sodium_crypto_sign_detached(
                "HAFZATECH-INSTALL-STATUS-RECEIPT-V1\n".$raw,
                sodium_crypto_sign_secretkey($pair)))];
    }

    private function grant(string $pair, string $license, string $installation,
        string $customer, string $domain, int $revision, string $from, string $to): array
    {
        $claim = [
            'api_version' => 'v1', 'issuer' => 'HAFZATECH', 'product' => 'VenueDesk',
            'license_id' => $license, 'installation_id' => $installation,
            'customer_id' => $customer, 'revision' => $revision, 'term_months' => 12,
            'edition' => 'standard', 'approved_domains' => [$domain],
            'not_before' => $from, 'expires_at' => $to,
            'limits' => ['max_halls' => 5, 'max_staff' => 20],
            'features' => ['booking' => true],
        ];
        $raw = json_encode($claim, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['key_id' => 'delivery-test', 'payload' => $this->b64($raw),
            'signature' => $this->b64(sodium_crypto_sign_detached(
                "HAFZATECH-LICENSE-GRANT-V1\n".$raw,
                sodium_crypto_sign_secretkey($pair)))];
    }

    public function test_central_delivery_accepts_and_preserves_active_term_across_renewal(): void
    {
        config()->set('central_control.activation_enabled', true);
        $identity = app(CentralInstallationIdentity::class)->initialize();
        $customer = (string) Str::uuid();
        $license = (string) Str::uuid();
        $domain = 'delivery.example.test';
        $company = \App\Models\Company::create(['name' => 'Delivery Venue',
            'slug' => 'delivery-venue']);
        DB::table('central_installation_activation')->insert([
            'singleton_id' => 1, 'company_id' => $company->id,
            'installation_id' => $identity['installation_id'],
            'customer_id' => $customer, 'approved_domain' => $domain,
            'status' => 'registered_pending_license', 'central_registered_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pair = sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys', [
            'delivery-test' => $this->b64(sodium_crypto_sign_publickey($pair)),
        ]);
        $now = new DateTimeImmutable('2026-11-01T00:00:00Z');
        $nonce = $this->b64(random_bytes(32));
        $service = app(CentralVerifiedLicenseDeliveryService::class);
        $grant = $this->grant($pair, $license, $identity['installation_id'],
            $customer, $domain, 1, '2026-10-01T00:00:00Z', '2027-10-01T00:00:00Z');
        $accepted = $service->accept($grant,
            $this->receipt($pair, $identity['installation_id'], $customer, $nonce, 1,
                '2026-11-01T00:00:00Z'),
            $customer, $domain, $nonce, $now);
        $this->assertTrue($accepted['active']);
        $this->assertSame(1, $accepted['revision']);

        $renewalNonce = $this->b64(random_bytes(32));
        $renewal = $this->grant($pair, $license, $identity['installation_id'],
            $customer, $domain, 2, '2027-10-01T00:00:00Z', '2028-10-01T00:00:00Z');
        $next = $service->accept($renewal,
            $this->receipt($pair, $identity['installation_id'], $customer,
                $renewalNonce, 2, '2026-11-01T00:00:00Z'),
            $customer, $domain, $renewalNonce, $now);
        $this->assertSame(2, $next['revision']);
        $this->assertTrue($next['active']);
        $stored = DB::table('central_signed_license_cache')->where('singleton_id', 1)->first();
        $this->assertSame(2, (int) $stored->revision);
        $this->assertSame(1, (int) $stored->previous_revision);
    }

    public function test_tampered_receipt_and_foreign_domain_are_rejected(): void
    {
        config()->set('central_control.activation_enabled', true);
        $identity = app(CentralInstallationIdentity::class)->initialize();
        $customer = (string) Str::uuid();
        $domain = 'delivery-negative.example.test';
        $company = \App\Models\Company::create(['name' => 'Delivery Negative',
            'slug' => 'delivery-negative']);
        DB::table('central_installation_activation')->insert([
            'singleton_id' => 1, 'company_id' => $company->id,
            'installation_id' => $identity['installation_id'],
            'customer_id' => $customer, 'approved_domain' => $domain,
            'status' => 'registered_pending_license', 'central_registered_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pair = sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys', [
            'delivery-test' => $this->b64(sodium_crypto_sign_publickey($pair)),
        ]);
        $now = new DateTimeImmutable('2026-11-01T00:00:00Z');
        $nonce = $this->b64(random_bytes(32));
        $grant = $this->grant($pair, (string) Str::uuid(), $identity['installation_id'],
            $customer, 'foreign.example.test', 1,
            '2026-10-01T00:00:00Z', '2027-10-01T00:00:00Z');
        $receipt = $this->receipt($pair, $identity['installation_id'], $customer,
            $nonce, 1, '2026-11-01T00:00:00Z');
        $receipt['signature'] = $this->b64(random_bytes(64));
        $this->expectException(\InvalidArgumentException::class);
        app(CentralVerifiedLicenseDeliveryService::class)->accept(
            $grant, $receipt, $customer, $domain, $nonce, $now);
    }
}
