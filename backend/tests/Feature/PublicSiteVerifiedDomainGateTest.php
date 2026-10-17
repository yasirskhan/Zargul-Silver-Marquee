<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicSiteRequestGate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PublicSiteVerifiedDomainGateTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Synthetic Marquee', 'slug' => 'dns-release-test']);
        $siteId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $siteId, 'company_id' => $company->id, 'name' => 'Synthetic Marquee',
        ]);
        $owner = User::create([
            'name' => 'Synthetic Owner', 'email' => 'dns-gate@example.test',
            'password' => 'test-only',
        ]);
        config([
            'public_site.enabled' => true,
            'public_site.host' => 'marquee.example.org',
            'public_site.company_id' => $company->id,
            'public_site.website_id' => $siteId,
            'public_site.indexing_enabled' => false,
            'public_site.require_verified_domain' => true,
        ]);

        return [$company, $siteId, $owner];
    }

    private function request(): Request
    {
        return Request::create('https://marquee.example.org/');
    }

    private function deny(int $expected, ?Request $request = null): void
    {
        try {
            app(PublicSiteRequestGate::class)->authorize($request ?? $this->request());
            $this->fail('Unverified or mismatched public host passed the gate.');
        } catch (HttpException $e) {
            $this->assertSame($expected, $e->getStatusCode());
        }
    }

    private function claim(Company $company, string $siteId, User $owner, string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('site_domain_claims')->insert([
            'id' => $id, 'company_id' => $company->id,
            'website_id' => $siteId, 'domain' => 'marquee.example.org',
            'token_hash' => str_repeat('a', 64), 'status' => $status,
            'requested_by' => $owner->id,
            'verified_by' => $status === 'verified' ? $owner->id : null,
            'verified_at' => $status === 'verified' ? now() : null,
            'expires_at' => now()->addDays(7),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    public function test_missing_and_pending_claims_fail_but_same_site_verified_dns_passes(): void
    {
        [$company, $siteId, $owner] = $this->fixture();
        $this->deny(503);
        $id = $this->claim($company, $siteId, $owner, 'pending');
        $this->deny(503);
        DB::table('site_domain_claims')->where('id', $id)->update([
            'status' => 'verified', 'verified_by' => $owner->id, 'verified_at' => now(),
        ]);
        $this->assertSame([
            'host' => 'marquee.example.org',
            'companyId' => $company->id, 'websiteId' => $siteId,
        ], app(PublicSiteRequestGate::class)->authorize($this->request()));
        $this->deny(404, Request::create('https://forged.example.org/'));
        $this->assertFalse((bool) config('public_site.indexing_enabled'));
    }

    public function test_verified_dns_claim_never_crosses_tenant_or_website_release(): void
    {
        [$company, $siteId, $owner] = $this->fixture();
        $this->claim($company, $siteId, $owner, 'verified');
        config(['public_site.website_id' => (string) Str::uuid()]);
        $this->deny(503);
        config(['public_site.website_id' => $siteId,
            'public_site.company_id' => (string) Str::uuid()]);
        $this->deny(503);
    }

    public function test_verified_dns_claim_does_not_enable_disabled_operator_release(): void
    {
        [$company, $siteId, $owner] = $this->fixture();
        $this->claim($company, $siteId, $owner, 'verified');
        config(['public_site.enabled' => false]);
        $this->deny(404);
    }
}
