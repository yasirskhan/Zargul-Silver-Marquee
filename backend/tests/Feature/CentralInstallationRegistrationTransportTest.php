<?php

namespace Tests\Feature;

use App\Services\CentralInstallationRegistrationTransport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CentralInstallationRegistrationTransportTest extends TestCase
{
    use RefreshDatabase;

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function test_release_gate_prevents_any_request_or_identity_creation(): void
    {
        Http::preventStrayRequests();
        config()->set('central_control.activation_enabled', false);
        try {
            app(CentralInstallationRegistrationTransport::class)->register(
                (string) Str::uuid(), self::encode(random_bytes(32)),
                (string) Str::uuid(), 'licensed.example.test');
            $this->fail('Disabled central activation reached network.');
        } catch (AuthorizationException) {}
        Http::assertNothingSent();
        $this->assertDatabaseCount('central_installation_identity', 0);
    }

    public function test_exact_trusted_host_signed_proof_and_pending_license_status(): void
    {
        config()->set('central_control.activation_enabled', true);
        $enrollment = (string) Str::uuid();
        $token = self::encode(random_bytes(32));
        $customer = (string) Str::uuid();
        $nonce = self::encode(random_bytes(32));
        $receivedSignedProof = false;
        $challenge = 'https://lichall.hafzatech.com/api/v1/installations/challenge';
        $registration = 'https://lichall.hafzatech.com/api/v1/installations/register';

        Http::fake([
            $challenge => function (Request $request) use ($enrollment, $token, $nonce) {
                $this->assertSame($enrollment, $request['enrollment_id']);
                $this->assertSame($token, $request['enrollment_token']);
                return Http::response([
                    'api_version' => 'v1', 'nonce' => $nonce, 'expires_in_seconds' => 300,
                ], 200);
            },
            $registration => function (Request $request) use ($customer, $nonce, $enrollment, &$receivedSignedProof) {
                $data = $request->data();
                $public = base64_decode(strtr($data['public_key'], '-_', '+/'), true);
                $signature = base64_decode(strtr($data['signature'], '-_', '+/'), true);
                $message = implode("\n", [
                    'HAFZATECH-INSTALL-REGISTER-V1', $data['installation_id'],
                    $customer, $enrollment, 'licensed.example.test', $nonce, $data['public_key'],
                ]);
                $receivedSignedProof = sodium_crypto_sign_verify_detached(
                    $signature, $message, $public);
                return Http::response([
                    'api_version' => 'v1', 'installation_id' => $data['installation_id'],
                    'customer_id' => $customer, 'status' => 'registered_pending_license',
                ], 201);
            },
        ]);
        $result = app(CentralInstallationRegistrationTransport::class)->register(
            $enrollment, $token, $customer, 'licensed.example.test');
        $this->assertTrue($receivedSignedProof);
        $this->assertSame('registered_pending_license', $result['status']);
        $this->assertDatabaseCount('central_installation_identity', 1);
        $this->assertDatabaseCount('finance_journals', 0);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === $challenge);
        Http::assertSent(fn (Request $request) => $request->url() === $registration);
    }

    public function test_no_following_redirects_or_false_success_on_wrong_central_response(): void
    {
        config()->set('central_control.activation_enabled', true);
        Http::fake([
            'https://lichall.hafzatech.com/api/v1/installations/challenge' =>
                Http::response('', 302, ['Location' => 'https://phishing.example.test/steal']),
        ]);
        try {
            app(CentralInstallationRegistrationTransport::class)->register(
                (string) Str::uuid(), self::encode(random_bytes(32)),
                (string) Str::uuid(), 'licensed.example.test');
            $this->fail('Redirect was accepted or followed.');
        } catch (InvalidArgumentException) {}
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) =>
            str_contains($request->url(), 'phishing.example.test'));
        $this->assertDatabaseCount('central_installation_identity', 1);
        $this->assertDatabaseCount('central_signed_license_cache', 0);
    }
}
