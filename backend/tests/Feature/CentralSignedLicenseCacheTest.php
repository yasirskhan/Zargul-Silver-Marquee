<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use App\Services\CentralSignedLicenseCache;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CentralSignedLicenseCacheTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:string,1:array<string,mixed>,2:string,3:string} */
    private function fixture(): array
    {
        config()->set('central_control.activation_enabled', true);
        $identity = app(CentralInstallationIdentity::class)->initialize();
        $pair = sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys', [
            'test-only' => self::url(sodium_crypto_sign_publickey($pair)),
        ]);
        $customer = (string) Str::uuid();
        $license = (string) Str::uuid();
        return [$pair, [
            'api_version'=>'v1', 'issuer'=>'HAFZATECH', 'product'=>'VenueDesk',
            'license_id'=>$license, 'customer_id'=>$customer,
            'installation_id'=>$identity['installation_id'],
            'revision'=>1, 'term_months'=>6, 'edition'=>'standard',
            'approved_domains'=>['testvenue.example.test'],
            'not_before'=>'2026-10-01T00:00:00Z',
            'expires_at'=>'2027-04-01T00:00:00Z',
            'limits'=>['max_halls'=>2, 'max_staff'=>10],
            'features'=>['booking'=>true],
        ], $customer, $license];
    }

    private static function url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function envelope(array $grant, string $pair): array
    {
        $raw = json_encode($grant, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return [
            'key_id'=>'test-only',
            'payload'=>self::url($raw),
            'signature'=>self::url(sodium_crypto_sign_detached(
                'HAFZATECH-LICENSE-GRANT-V1'."\n".$raw, sodium_crypto_sign_secretkey($pair)
            )),
        ];
    }

    public function test_default_off_activation_never_accepts_a_license(): void
    {
        [$pair, $grant, $customer] = $this->fixture();
        config()->set('central_control.activation_enabled', false);
        $this->expectException(AuthorizationException::class);
        app(CentralSignedLicenseCache::class)->accept($this->envelope($grant, $pair),
            $customer, 'testvenue.example.test', new DateTimeImmutable('2026-11-01T00:00:00Z'));
    }

    public function test_cache_is_encrypted_idempotent_and_requires_strictly_new_signed_revision(): void
    {
        [$pair, $grant, $customer, $license] = $this->fixture();
        $service = app(CentralSignedLicenseCache::class);
        $now = new DateTimeImmutable('2026-11-01T00:00:00Z');
        $v1 = $this->envelope($grant, $pair);
        $this->assertSame([
            'license_id'=>$license, 'revision'=>1, 'changed'=>true,
        ], $service->accept($v1, $customer, 'testvenue.example.test', $now));
        $this->assertSame([
            'license_id'=>$license, 'revision'=>1, 'changed'=>false,
        ], $service->accept($v1, $customer, 'testvenue.example.test', $now));
        $stored = DB::table('central_signed_license_cache')->first();
        $this->assertStringNotContainsString('"HAFZATECH"', $stored->encrypted_signed_envelope);
        $this->assertTrue($service->locallyVerified($customer, 'testvenue.example.test', $now)['active']);

        $grant['revision'] = 2;
        $grant['expires_at'] = '2027-10-01T00:00:00Z';
        $v2 = $this->envelope($grant, $pair);
        $this->assertTrue($service->accept($v2, $customer, 'testvenue.example.test', $now)['changed']);
        $this->assertSame(2, (int) DB::table('central_signed_license_cache')->value('revision'));
        try {
            $service->accept($v1, $customer, 'testvenue.example.test', $now);
            $this->fail('Signed rollback was accepted.');
        } catch (InvalidArgumentException) {}
        $this->assertSame(2, (int) DB::table('central_signed_license_cache')->value('revision'));
        $this->assertDatabaseCount('central_signed_license_cache', 1);
    }

    public function test_cross_customer_forged_revision_and_tampered_cached_bytes_fail_closed(): void
    {
        [$pair, $grant, $customer] = $this->fixture();
        $now = new DateTimeImmutable('2026-11-01T00:00:00Z');
        $service = app(CentralSignedLicenseCache::class);
        $good = $this->envelope($grant, $pair);
        try {
            $service->accept($good, (string) Str::uuid(), 'testvenue.example.test', $now);
            $this->fail('Foreign customer signed grant accepted.');
        } catch (InvalidArgumentException) {}
        $bad = $good;
        $bad['payload'] = self::url('{}');
        try {
            $service->accept($bad, $customer, 'testvenue.example.test', $now);
            $this->fail('Forged payload accepted.');
        } catch (InvalidArgumentException) {}
        $service->accept($good, $customer, 'testvenue.example.test', $now);
        DB::table('central_signed_license_cache')->where('singleton_id', 1)
            ->update(['envelope_sha256'=>str_repeat('a', 64)]);
        $this->expectException(InvalidArgumentException::class);
        $service->locallyVerified($customer, 'testvenue.example.test', $now);
    }
}
