<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteDomainOwnershipService;
use App\Services\SiteDomainTxtResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class SiteDomainOwnershipServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $slug = 'domain-venue', bool $publisher = true): array
    {
        $company = Company::create(['name' => $slug, 'slug' => $slug]);
        $user = User::create([
            'name' => 'DNS Publisher', 'email' => $slug.'@example.test', 'password' => 'synthetic-only',
        ]);
        $user->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $publisher ? 'owner' : 'manager', 'can_edit_site' => ! $publisher,
        ]);
        $websiteId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $websiteId, 'company_id' => $company->id, 'name' => $slug,
        ]);
        $this->be($user);

        return [$company, $user, $websiteId];
    }

    private function resolver(): SiteDomainTxtResolver
    {
        $resolver = new class extends SiteDomainTxtResolver {
            public array $answers = [];

            public function lookupTxt(string $name): array
            {
                return $this->answers[$name] ?? [];
            }
        };
        $this->app->instance(SiteDomainTxtResolver::class, $resolver);
        return $resolver;
    }

    public function test_owner_proves_exact_dns_txt_but_cannot_auto_launch_public_website(): void
    {
        [$company, $owner, $site] = $this->fixture();
        $resolver = $this->resolver();
        $service = app(SiteDomainOwnershipService::class);
        $this->assertFalse((bool) config('public_site.enabled'));
        $this->assertFalse((bool) config('public_site.indexing_enabled'));

        $challenge = $service->request($owner, $company->id, $site,
            'marquee.example.org', 'Prepare private domain proof');
        $this->assertSame('_venuedesk-verification.marquee.example.org', $challenge['txtName']);
        $this->assertStringStartsWith('venuedesk-verification=', $challenge['txtValue']);
        $this->assertSame(40, strlen(substr($challenge['txtValue'], strlen('venuedesk-verification='))));
        $this->assertSame('pending', DB::table('site_domain_claims')->value('status'));
        $record = DB::table('site_domain_claims')->first();
        $this->assertNotSame($challenge['txtValue'], $record->token_hash);
        $this->assertSame(hash('sha256', substr($challenge['txtValue'], strlen('venuedesk-verification='))),
            $record->token_hash);
        $audits = json_encode(DB::table('audit_events')->get()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($challenge['txtValue'], $audits);

        $resolver->answers[$challenge['txtName']] = ['venuedesk-verification=incorrect'];
        try {
            $service->verify($owner, $company->id, $site,
                $challenge['domain'], 'Confirm owner DNS record');
            $this->fail('Wrong DNS challenge was accepted.');
        } catch (ConflictHttpException) {
            $this->assertSame('pending', DB::table('site_domain_claims')->value('status'));
        }
        $resolver->answers[$challenge['txtName']] = [$challenge['txtValue']];
        $service->verify($owner, $company->id, $site, $challenge['domain'], 'Confirm owner DNS record');
        $this->assertSame('verified', DB::table('site_domain_claims')->value('status'));
        $this->assertSame($owner->id, (int) DB::table('site_domain_claims')->value('verified_by'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.domain_ownership_verified')->count());
        $this->assertFalse((bool) config('public_site.enabled'));
        $this->assertFalse((bool) config('public_site.indexing_enabled'));
        $this->assertSame('', config('public_site.host'));
        $this->assertNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
    }

    public function test_domain_request_and_verification_roll_back_if_immutable_audit_cannot_be_written(): void
    {
        [$company, $owner, $site] = $this->fixture('domain-atomic-audit');
        $resolver = $this->resolver();
        $service = app(SiteDomainOwnershipService::class);
        $this->rejectAuditWrites();
        try {
            $service->request($owner, $company->id, $site, 'atomic.marquee.org',
                'Prepare audited domain claim');
            $this->fail('A domain claim survived an audit write failure.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('Injected domain audit rejection', $exception->getMessage());
        } finally {
            $this->allowAuditWrites();
        }
        $this->assertSame(0, DB::table('site_domain_claims')->count());
        $this->assertSame(0, DB::table('audit_events')->count());

        $claim = $service->request($owner, $company->id, $site,
            'atomic.marquee.org', 'Create a valid audited claim');
        $resolver->answers[$claim['txtName']] = [$claim['txtValue']];
        $this->rejectAuditWrites();
        try {
            $service->verify($owner, $company->id, $site, $claim['domain'],
                'Audit must precede verified status');
            $this->fail('DNS verification survived an audit write failure.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('Injected domain audit rejection', $exception->getMessage());
        } finally {
            $this->allowAuditWrites();
        }
        $this->assertSame('pending', DB::table('site_domain_claims')->value('status'));
        $this->assertNull(DB::table('site_domain_claims')->value('verified_at'));
        $this->assertSame(1, DB::table('audit_events')->count());
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'website.domain_ownership_verified')->count());
    }

    public function test_challenge_rotated_during_dns_lookup_cannot_verify_an_outdated_snapshot(): void
    {
        [$company, $owner, $site] = $this->fixture('rotate-while-resolving');
        $service = app(SiteDomainOwnershipService::class);
        $claim = $service->request($owner, $company->id, $site,
            'rotation.marquee.org', 'Issue first DNS proof');

        $resolver = new class extends SiteDomainTxtResolver {
            public string $oldProof = '';

            public function lookupTxt(string $name): array
            {
                // Race injected after untrusted DNS lookup has started but
                // before the service locks/rechecks the current claim hash.
                DB::table('site_domain_claims')->update([
                    'token_hash' => hash('sha256', 'a-new-challenge-not-in-dns'),
                ]);
                return [$this->oldProof];
            }
        };
        $resolver->oldProof = $claim['txtValue'];
        $this->app->instance(SiteDomainTxtResolver::class, $resolver);
        // The request service was resolved before fake DNS injection.
        // Resolve verification anew so the test exercises the interleaved
        // database mutation, rather than merely a failed real DNS lookup.
        $verifier = app(SiteDomainOwnershipService::class);

        try {
            $verifier->verify($owner, $company->id, $site, $claim['domain'],
                'Reject old proof after concurrent token rotation');
            $this->fail('Old proof was accepted after challenge rotation.');
        } catch (ConflictHttpException) {
            $this->assertSame('pending', DB::table('site_domain_claims')->value('status'));
            $this->assertSame(hash('sha256', 'a-new-challenge-not-in-dns'),
                DB::table('site_domain_claims')->value('token_hash'));
            $this->assertSame(0, DB::table('audit_events')
                ->where('action', 'website.domain_ownership_verified')->count());
        }
    }

    private function rejectAuditWrites(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_test_domain_audit_insert() RETURNS trigger
LANGUAGE plpgsql AS $
BEGIN
    IF NEW.action IN ('website.domain_claim_requested', 'website.domain_ownership_verified') THEN
        RAISE EXCEPTION 'Injected domain audit rejection';
    END IF;
    RETURN NEW;
END;
$;
SQL);
        DB::statement('CREATE TRIGGER fail_test_domain_audit_insert_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_test_domain_audit_insert()');
    }

    private function allowAuditWrites(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fail_test_domain_audit_insert_trigger ON audit_events');
        DB::statement('DROP FUNCTION IF EXISTS fail_test_domain_audit_insert()');
    }

    public function test_unverified_and_reserved_domain_claims_fail_without_persisting(): void
    {
        [$company, $owner, $site] = $this->fixture('invalid-dns');
        $service = app(SiteDomainOwnershipService::class);
        foreach (['localhost', '127.0.0.1', 'http://marquee.com',
            'www.internal', 'x.example.test', 'marquee.com:443', '<marquee.com>'] as $invalid) {
            try {
                $service->request($owner, $company->id, $site, $invalid, 'Reject invalid owner domain');
                $this->fail('Unsupported domain '.$invalid.' accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, DB::table('site_domain_claims')->count());
            }
        }
    }

    public function test_editor_cannot_claim_and_other_company_cannot_take_claimed_domain(): void
    {
        [$company, $owner, $site] = $this->fixture('primary-domain');
        $service = app(SiteDomainOwnershipService::class);
        $service->request($owner, $company->id, $site, 'events.marquee.net', 'Claim private domain');
        [$foreign, $editor, $foreignSite] = $this->fixture('foreign-domain', false);
        try {
            $service->request($editor, $foreign->id, $foreignSite, 'events.marquee.net', 'Forged editor request');
            $this->fail('Unprivileged editor claimed a domain.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('site_domain_claims')->count());
        }
        CompanyMembership::where('company_id', $foreign->id)->where('user_id', $editor->id)
            ->update(['role' => 'owner', 'can_edit_site' => false]);
        try {
            $service->request($editor, $foreign->id, $foreignSite,
                'events.marquee.net', 'Cross company domain takeover');
            $this->fail('Claimed domain was stolen.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('site_domain_claims')->count());
        }
        $this->assertSame($company->id, DB::table('site_domain_claims')->value('company_id'));
    }

    public function test_publisher_revoked_after_request_cannot_verify_even_with_correct_dns_txt(): void
    {
        [$company, $owner, $site] = $this->fixture('dns-revocation');
        $resolver = $this->resolver();
        $service = app(SiteDomainOwnershipService::class);
        $challenge = $service->request($owner, $company->id, $site,
            'verify.marquee.org', 'Request DNS ownership proof');
        $resolver->answers[$challenge['txtName']] = [$challenge['txtValue']];
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);

        // Auth still holds an old hydrated owner object; live database
        // authorization must reject even matching DNS proof.
        try {
            $service->verify($owner, $company->id, $site, 'verify.marquee.org',
                'Reject disabled publisher');
            $this->fail('Disabled publisher verified a DNS domain.');
        } catch (AuthorizationException) {
            $this->assertSame('pending',
                DB::table('site_domain_claims')->where('website_id', $site)->value('status'));
            $this->assertSame(0,
                DB::table('audit_events')->where('action', 'website.domain_ownership_verified')->count());
        }
    }

    public function test_expired_and_rotated_dns_tokens_cannot_be_verified(): void
    {
        [$company, $owner, $site] = $this->fixture('rotate-domain');
        $resolver = $this->resolver();
        $service = app(SiteDomainOwnershipService::class);
        $original = $service->request($owner, $company->id, $site, 'silver-marquee.com', 'Initial challenge');
        $rotated = $service->request($owner, $company->id, $site, 'silver-marquee.com', 'Rotate old challenge');
        $resolver->answers[$original['txtName']] = [$original['txtValue']];
        try {
            $service->verify($owner, $company->id, $site, 'silver-marquee.com', 'Reject old TXT token');
            $this->fail('Previously rotated DNS token was accepted.');
        } catch (ConflictHttpException) {
            $this->assertSame('pending', DB::table('site_domain_claims')->value('status'));
        }
        $resolver->answers[$rotated['txtName']] = [$rotated['txtValue']];
        DB::table('site_domain_claims')->update(['expires_at' => now()->subMinute()]);
        try {
            $service->verify($owner, $company->id, $site, 'silver-marquee.com', 'Expired domain proof');
            $this->fail('Expired domain challenge was accepted.');
        } catch (ConflictHttpException) {
            $this->assertSame('pending', DB::table('site_domain_claims')->value('status'));
        }
    }
}
