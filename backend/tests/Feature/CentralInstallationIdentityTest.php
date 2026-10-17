<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CentralInstallationIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_is_disabled_by_default_and_no_tenant_or_finance_records_change(): void
    {
        config()->set('central_control.activation_enabled', false);
        $service = app(CentralInstallationIdentity::class);
        $this->assertSame('https://lichall.hafzatech.com/api/v1/installations/challenge',
            $service->trustedEndpoint('challenge'));
        $this->assertSame('https://lichall.hafzatech.com/api/v1/installations/register',
            $service->trustedEndpoint('register'));
        try {
            $service->initialize();
            $this->fail('Central activation bypassed default off gate.');
        } catch (AuthorizationException) {}
        $this->assertDatabaseCount('central_installation_identity', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_stable_encrypted_installation_identity_signs_exact_server_contract_without_network(): void
    {
        config()->set('central_control.activation_enabled', true);
        $service = app(CentralInstallationIdentity::class);
        $first = $service->initialize();
        $this->assertSame($first, $service->initialize());
        $this->assertTrue(Str::isUuid($first['installation_id']));
        $row = DB::table('central_installation_identity')->first();
        $this->assertSame(1, (int) $row->singleton_id);
        $this->assertNotSame($first['public_key'], $row->encrypted_private_key);
        $this->assertStringNotContainsString('PRIVATE KEY', $row->encrypted_private_key);

        $enrollment = (string) Str::uuid();
        $customer = (string) Str::uuid();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $payload = $service->signRegistration($enrollment, $token, $customer,
            'hall.example.test', $nonce);
        $expected = implode("\n", ['HAFZATECH-INSTALL-REGISTER-V1', $first['installation_id'],
            $customer, $enrollment, 'hall.example.test', $nonce, $first['public_key']]);
        $public = base64_decode(strtr($payload['public_key'], '-_', '+/'), true);
        $signature = base64_decode(strtr($payload['signature'], '-_', '+/'), true);
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, $expected, $public));
        $this->assertFalse(sodium_crypto_sign_verify_detached($signature,
            str_replace('hall.example.test', 'foreign.example.test', $expected), $public));
        $this->assertSame($token, $payload['enrollment_token']);
        $this->assertDatabaseCount('central_installation_identity', 1);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_malformed_domains_invalid_nonces_and_unrecognized_endpoint_fail_closed(): void
    {
        config()->set('central_control.activation_enabled', true);
        $service = app(CentralInstallationIdentity::class);
        $service->initialize();
        $id = (string) Str::uuid();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        foreach (['https://hall.example.test', '127.0.0.1', 'HALL.example.test'] as $invalid) {
            try {
                $service->signRegistration($id, $token, $id, $invalid, $token);
                $this->fail('Invalid domain permitted.');
            } catch (InvalidArgumentException) {}
        }
        $this->expectException(InvalidArgumentException::class);
        $service->signRegistration($id, $token, $id, 'hall.example.test', 'bad-nonce');
    }
}
