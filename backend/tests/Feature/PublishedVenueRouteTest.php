<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteBrandingService;
use App\Services\SitePagePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublishedVenueRouteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Test Public Venue', 'slug' => 'test-public-venue']);
        $owner = User::create(['name' => 'Owner', 'email' => 'published-route@example.test',
            'password' => 'synthetic-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $site = (string) Str::uuid();
        $page = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $site, 'company_id' => $company->id,
            'name' => 'Private unpublished venue']);
        DB::table('site_pages')->insert(['id' => $page, 'company_id' => $company->id,
            'website_id' => $site, 'slug' => '/', 'title' => 'Approved venue home']);
        $this->be($owner);
        return [$company, $owner, $site, $page];
    }

    private function enable(string $companyId, string $websiteId): void
    {
        config()->set('public_site.enabled', true);
        config()->set('public_site.host', 'venue.example.test');
        config()->set('public_site.company_id', $companyId);
        config()->set('public_site.website_id', $websiteId);
    }

    private function publish(Company $company, User $owner, string $site, string $page): void
    {
        $branding = app(SiteBrandingService::class);
        $branding->saveDraft($owner, $company->id, $site, [
            'siteName' => 'Zargul Approved Site', 'tagline' => 'Real venue pending launch review',
            'themePreset' => 'ivory', 'fontPreset' => 'modern',
            'logoMediaId' => null, 'faviconMediaId' => null,
        ], 0, 'Synthetic owner content');
        $branding->publish($owner, $company->id, $site, 1, 'Approve synthetic brand');
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $page, ['sections' => [[
            'id' => 'intro', 'type' => 'text',
            'data' => ['title' => 'Approved Hall Opening', 'body' => 'Only approved content'],
        ]]], 0, 'Draft synthetic website');
        $pages->publish($owner, $company->id, $page, 1, 'Publish synthetic landing');
    }

    public function test_disabled_public_route_preserves_existing_foundation_response(): void
    {
        $this->get('http://venue.example.test/')->assertOk()
            ->assertJsonPath('application', 'VenueDesk')
            ->assertJsonPath('state', 'foundation');
    }

    public function test_only_approved_host_and_both_published_revisions_can_serve_safe_html(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $this->enable($company->id, $site);
        $this->get('http://wrong.example.test/')->assertNotFound();
        $this->get('http://venue.example.test/')->assertNotFound();
        $this->publish($company, $owner, $site, $page);
        $response = $this->get('http://venue.example.test/')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cache-Control', 'no-store, private');
        $response->assertSee('Approved Hall Opening');
        $response->assertSee('Zargul Approved Site');
        $response->assertDontSee('site-media/private/');
        $response->assertDontSee('<script', false);
        $this->assertStringContainsString("script-src 'none'",
            $response->headers->get('Content-Security-Policy'));
        app(SitePagePublishingService::class)->saveDraft($owner, $company->id,
            $page, ['sections' => [[
                'id' => 'draft', 'type' => 'text',
                'data' => ['title' => 'UNPUBLISHED PRIVATE DRAFT'],
            ]]], 1, 'Keep draft unpublished');
        $this->get('http://venue.example.test/')->assertOk()
            ->assertDontSee('UNPUBLISHED PRIVATE DRAFT')
            ->assertSee('Approved Hall Opening');
    }

    public function test_tenant_or_site_mismatch_and_missing_operator_configuration_fail_closed(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $this->publish($company, $owner, $site, $page);
        $this->enable((string) Str::uuid(), $site);
        $this->get('http://venue.example.test/')->assertNotFound();
        $this->enable($company->id, (string) Str::uuid());
        $this->get('http://venue.example.test/')->assertNotFound();
        $this->enable($company->id, $site);
        config()->set('public_site.host', 'venue.example.test.evil.test');
        $this->get('http://venue.example.test/')->assertNotFound();
        config()->set('public_site.host', '*');
        $this->get('http://venue.example.test/')->assertStatus(503);
        config()->set('public_site.host', 'venue.example.test');
        config()->set('public_site.website_id', '');
        $this->get('http://venue.example.test/')->assertStatus(503);
    }
}
