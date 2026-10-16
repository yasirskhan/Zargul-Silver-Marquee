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
        $response->assertSee('https://venue.example.test/');
        $response->assertSee('content="noindex, nofollow"', false);
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

    public function test_robots_and_indexing_require_separate_opt_in_and_approved_home(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $this->enable($company->id, $site);
        config()->set('public_site.indexing_enabled', true);
        $this->get('http://venue.example.test/robots.txt')->assertOk()
            ->assertSee('Disallow: /');
        $this->get('http://venue.example.test/')->assertNotFound();
        $this->publish($company, $owner, $site, $page);
        $this->get('http://venue.example.test/robots.txt')->assertOk()
            ->assertSee('Allow: /');
        $this->get('http://venue.example.test/')->assertOk()
            ->assertSee('content="index, follow"', false);
        config()->set('public_site.indexing_enabled', false);
        $this->get('http://venue.example.test/robots.txt')->assertOk()
            ->assertSee('Disallow: /');
        $this->get('http://venue.example.test/')->assertOk()
            ->assertSee('content="noindex, nofollow"', false);
        $this->get('http://wrong.example.test/robots.txt')->assertNotFound();
        config()->set('public_site.enabled', false);
        $this->get('http://venue.example.test/robots.txt')->assertNotFound();
    }

    public function test_sitemap_lists_only_valid_published_allowlisted_pages_after_indexing_signoff(): void
    {
        [$company, $owner, $site, $home] = $this->fixture();
        $this->enable($company->id, $site);
        config()->set('public_site.indexing_enabled', true);
        $this->get('http://venue.example.test/sitemap.xml')->assertNotFound();
        $this->publish($company, $owner, $site, $home);
        $this->get('http://venue.example.test/robots.txt')->assertOk()
            ->assertSee('Sitemap: https://venue.example.test/sitemap.xml');
        $this->get('http://venue.example.test/sitemap.xml')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('<urlset', false)
            ->assertSee('https://venue.example.test/')
            ->assertDontSee('https://venue.example.test/halls');
        $hallPage = (string) Str::uuid();
        DB::table('site_pages')->insert([
            'id' => $hallPage, 'company_id' => $company->id,
            'website_id' => $site, 'slug' => '/halls', 'title' => 'Owners hall page',
        ]);
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $hallPage,
            ['sections' => []], 0, 'Draft-only sitemap page');
        $this->get('http://venue.example.test/sitemap.xml')->assertOk()
            ->assertDontSee('https://venue.example.test/halls');
        $pages->publish($owner, $company->id, $hallPage, 1, 'Approve hall page for public website');
        $this->get('http://venue.example.test/sitemap.xml')->assertOk()
            ->assertSee('https://venue.example.test/halls')
            ->assertDontSee('site-media/private/');
        $this->get('http://wrong.example.test/sitemap.xml')->assertNotFound();
        config()->set('public_site.indexing_enabled', false);
        $this->get('http://venue.example.test/sitemap.xml')->assertNotFound();
        $this->get('http://venue.example.test/robots.txt')->assertOk()
            ->assertSee('Disallow: /')->assertDontSee('Sitemap:');
    }

    public function test_only_published_allowlisted_secondary_page_uses_same_approved_hostname(): void
    {
        [$company, $owner, $site, $home] = $this->fixture();
        $this->publish($company, $owner, $site, $home);
        $hallsPage = (string) Str::uuid();
        DB::table('site_pages')->insert([
            'id' => $hallsPage, 'company_id' => $company->id,
            'website_id' => $site, 'slug' => '/halls',
            'title' => 'Official Hall Details',
        ]);
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $hallsPage, ['sections' => [[
            'id' => 'halls', 'type' => 'text',
            'data' => ['title' => 'Approved hall catalogue'],
        ]]], 0, 'Create hall information page');
        $this->enable($company->id, $site);
        $this->get('http://venue.example.test/halls')->assertNotFound();
        $this->get('http://venue.example.test/')->assertOk()
            ->assertDontSee('Published venue pages')
            ->assertDontSee('href="/halls"', false);
        $pages->publish($owner, $company->id, $hallsPage, 1, 'Approve hall page');
        $this->get('http://venue.example.test/')->assertOk()
            ->assertSee('Published venue pages')
            ->assertSee('href="/halls"', false)
            ->assertSee('Official Hall Details');
        $this->get('http://venue.example.test/halls')->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('Approved hall catalogue')
            ->assertSee('https://venue.example.test/halls')
            ->assertDontSee('site-media/private/');
        $this->get('http://wrong.example.test/halls')->assertNotFound();
        $this->get('http://venue.example.test/contact')->assertNotFound();
        $this->get('http://venue.example.test/secret-private')->assertNotFound();
        config()->set('public_site.enabled', false);
        $this->get('http://venue.example.test/halls')->assertNotFound();
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
