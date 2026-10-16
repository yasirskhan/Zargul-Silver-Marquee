<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SiteDomainOwnershipService
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly SiteDomainTxtResolver $resolver,
    ) {}

    /** Prove DNS control; verification NEVER changes public hostname or indexing gates. */
    public function request(
        User $actor, string $companyId, string $websiteId, string $candidate, string $reason
    ): array {
        $this->authorize($actor, $companyId);
        $domain = $this->domain($candidate);
        $this->reason($reason);

        return DB::transaction(function () use ($actor, $companyId, $websiteId, $domain, $reason): array {
            $this->lockWebsite($companyId, $websiteId);
            $this->authorize($actor, $companyId);
            $existing = DB::table('site_domain_claims')->where('domain', $domain)->first();
            if ($existing && ($existing->company_id !== $companyId
                || $existing->website_id !== $websiteId || $existing->status === 'verified')) {
                throw new ConflictHttpException('Domain claim cannot be replaced.');
            }

            $token = Str::random(40);
            $now = now();
            $expiresAt = $now->copy()->addDays(7);
            $id = $existing?->id ?? (string) Str::uuid();
            $values = [
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'requested_by' => $actor->getKey(),
                'verified_by' => null,
                'verified_at' => null,
                'expires_at' => $expiresAt,
                'updated_at' => $now,
            ];
            if ($existing) {
                DB::table('site_domain_claims')->where('id', $id)->update($values);
            } else {
                DB::table('site_domain_claims')->insert([
                    'id' => $id, 'company_id' => $companyId,
                    'website_id' => $websiteId, 'domain' => $domain,
                    'created_at' => $now, ...$values,
                ]);
            }
            $this->audit($actor, $companyId, $id, 'website.domain_claim_requested',
                ['websiteId' => $websiteId, 'domain' => $domain,
                    'status' => 'pending', 'expiresAt' => $expiresAt->toIso8601String()],
                $reason);

            return [
                'domain' => $domain, 'txtName' => '_venuedesk-verification.'.$domain,
                'txtValue' => 'venuedesk-verification='.$token,
                'expiresAt' => $expiresAt->toIso8601String(),
            ];
        }, 3);
    }

    public function verify(
        User $actor, string $companyId, string $websiteId, string $candidate, string $reason
    ): void {
        $this->authorize($actor, $companyId);
        $domain = $this->domain($candidate);
        $this->reason($reason);
        $claim = DB::table('site_domain_claims')->where('company_id', $companyId)
            ->where('website_id', $websiteId)->where('domain', $domain)->first();
        if (! $claim || $claim->status !== 'pending'
            || Carbon::parse($claim->expires_at)->lessThanOrEqualTo(now())) {
            throw new ConflictHttpException('A current pending domain challenge is required.');
        }

        // Resolve BEFORE beginning the write transaction. Recheck the exact
        // original challenge hash and current membership after both locks.
        $matchingTxt = false;
        foreach ($this->resolver->lookupTxt('_venuedesk-verification.'.$domain) as $record) {
            if (is_string($record) && str_starts_with($record, 'venuedesk-verification=')
                && preg_match('/\Avenuedesk-verification=([A-Za-z0-9]{40})\z/', $record, $match)
                && hash_equals($claim->token_hash, hash('sha256', $match[1]))) {
                $matchingTxt = true;
                break;
            }
        }
        if (! $matchingTxt) {
            throw new ConflictHttpException('DNS TXT proof is missing or does not match.');
        }

        DB::transaction(function () use ($actor, $companyId, $websiteId, $domain, $reason, $claim): void {
            $this->lockWebsite($companyId, $websiteId);
            $locked = DB::table('site_domain_claims')->where('id', $claim->id)
                ->where('company_id', $companyId)->where('website_id', $websiteId)
                ->lockForUpdate()->first();
            $this->authorize($actor, $companyId);
            if (! $locked || $locked->status !== 'pending'
                || $locked->token_hash !== $claim->token_hash
                || Carbon::parse($locked->expires_at)->lessThanOrEqualTo(now())) {
                throw new ConflictHttpException('DNS ownership challenge changed or expired.');
            }
            DB::table('site_domain_claims')->where('id', $claim->id)->update([
                'status' => 'verified', 'verified_by' => $actor->getKey(),
                'verified_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $companyId, $claim->id, 'website.domain_ownership_verified',
                ['websiteId' => $websiteId, 'domain' => $domain,
                    'status' => 'verified', 'operatorReleaseRequired' => true],
                $reason);
        }, 3);
    }

    private function domain(string $input): string
    {
        $host = strtolower(trim($input));
        if ($host !== $input && $input !== trim($input)) {
            throw new InvalidArgumentException('Enter only the exact domain name without whitespace.');
        }
        if (strlen($host) > 253 || ! preg_match(
            '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $host
        ) || preg_match('/\.(?:localhost|local|internal|invalid|test|example)\z/', $host)
            || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Enter a valid public DNS domain, not a URL or local address.');
        }
        return $host;
    }

    private function lockWebsite(string $companyId, string $websiteId): void
    {
        $site = DB::table('websites')->where('company_id', $companyId)
            ->where('id', $websiteId)->where('is_active', true)->lockForUpdate()->first();
        if (! $site) {
            throw new AuthorizationException('Website is not accessible.');
        }
    }

    private function reason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $reason)) {
            throw new InvalidArgumentException('Use a plain short reason without contact details.');
        }
    }

    private function authorize(User $actor, string $companyId): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            || ! $this->access->canPublishWebsite($actor, $companyId)) {
            throw new AuthorizationException('Only an authorized website publisher may verify domain ownership.');
        }
    }

    private function audit(
        User $actor, string $companyId, string $claimId, string $action, array $after, string $reason
    ): void {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(), 'action' => $action,
            'entity_type' => 'site_domain_claim', 'entity_id' => $claimId,
            'before_values' => null, 'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }
}
