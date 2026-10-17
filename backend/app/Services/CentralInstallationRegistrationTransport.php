<?php

namespace App\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Strictly outbound HTTPS transport for INSTALLATION IDENTITY enrollment.
 * Does not activate a paid license. No URL, IP, public key or signing key
 * can be supplied by a customer, staff member or the inbound HTTP request.
 */
final class CentralInstallationRegistrationTransport
{
    public function __construct(private readonly CentralInstallationIdentity $identity) {}

    /** @return array{installation_id:string,customer_id:string,status:string} */
    public function register(string $enrollmentId, string $oneUseToken,
        string $customerId, string $verifiedDomain): array
    {
        if (config('central_control.activation_enabled') !== true) {
            throw new AuthorizationException('Central enrollment has not been released.');
        }

        // Persistent local installation ID never changes on network retry.
        $local = $this->identity->initialize();
        // Registration survives repeated wizard requests and cannot silently
        // transfer this self-hosted installation to another vendor customer.
        $existing = DB::table('central_installation_activation')
            ->where('singleton_id', 1)->first();
        if ($existing) {
            if ($existing->installation_id !== $local['installation_id'] ||
                $existing->customer_id !== $customerId ||
                $existing->approved_domain !== $verifiedDomain) {
                throw new InvalidArgumentException('Existing central installation binding is immutable.');
            }

            return ['installation_id' => $local['installation_id'],
                'customer_id' => $customerId, 'status' => 'registered_pending_license'];
        }
        $challengeUrl = $this->identity->trustedEndpoint('challenge');
        $registerUrl = $this->identity->trustedEndpoint('register');
        $request = static fn () => Http::acceptJson()->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(3)->timeout(8);

        $challenge = $request()->post($challengeUrl, [
            'enrollment_id' => $enrollmentId,
            'enrollment_token' => $oneUseToken,
        ]);
        if ($challenge->status() !== 200) {
            // Do not echo central error bodies or enrollment secrets.
            throw new InvalidArgumentException('Trusted central enrollment challenge failed.');
        }
        $body = $challenge->json();
        if (! is_array($body) ||
            ($body['api_version'] ?? null) !== 'v1' ||
            ($body['expires_in_seconds'] ?? null) !== 300 ||
            ! is_string($body['nonce'] ?? null)) {
            throw new InvalidArgumentException('Untrusted central challenge format.');
        }
        $payload = $this->identity->signRegistration($enrollmentId,
            $oneUseToken, $customerId, $verifiedDomain, $body['nonce']);
        $answer = $request()->post($registerUrl, $payload);
        if ($answer->status() !== 201) {
            throw new InvalidArgumentException('Central identity registration did not succeed.');
        }
        $result = $answer->json();
        if (! is_array($result) ||
            ($result['api_version'] ?? null) !== 'v1' ||
            ($result['installation_id'] ?? null) !== $local['installation_id'] ||
            ($result['customer_id'] ?? null) !== $customerId ||
            ($result['status'] ?? null) !== 'registered_pending_license') {
            throw new InvalidArgumentException('Untrusted central registration acknowledgment.');
        }

        DB::transaction(function () use ($local, $customerId, $verifiedDomain): void {
            DB::select('SELECT pg_advisory_xact_lock(708116, 1114)');
            $bound = DB::table('central_installation_activation')
                ->where('singleton_id', 1)->lockForUpdate()->first();
            if ($bound) {
                if ($bound->installation_id !== $local['installation_id'] ||
                    $bound->customer_id !== $customerId ||
                    $bound->approved_domain !== $verifiedDomain) {
                    throw new InvalidArgumentException('Conflicting HAFZATECH customer enrollment.');
                }
                return;
            }
            DB::table('central_installation_activation')->insert([
                'singleton_id'=>1,
                'installation_id'=>$local['installation_id'],
                'customer_id'=>$customerId,
                'approved_domain'=>$verifiedDomain,
                'status'=>'registered_pending_license',
                'central_registered_at'=>now(),
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
        });

        return [
            'installation_id' => $local['installation_id'],
            'customer_id' => $customerId,
            'status' => 'registered_pending_license',
        ];
    }
}
