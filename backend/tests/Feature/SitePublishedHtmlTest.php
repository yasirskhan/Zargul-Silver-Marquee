<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteBrandingService;
use App\Services\SiteMediaService;
use App\Services\SitePagePublishingService;
use App\Services\SitePublishedHtmlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SitePublishedHtmlTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Silver HTML', 'slug' => 'silver-html']);
        $owner = User::create(['name' => 'Owner', 'email' => 'html-renderer@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $site = (string) Str::uuid();
        $page = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $site, 'company_id' => $company->id, 'name' => 'Silver HTML']);
        DB::table('site_pages')->insert([
            'id' => $page, 'company_id' => $company->id, 'website_id' => $site,
            'slug' => '/', 'title' => 'Official Home & Venue',
        ]);
        $this->be($owner);

        return [$company, $owner, $site, $page];
    }

    private function brand(User $owner, string $companyId, string $site): void
    {
        $branding = app(SiteBrandingService::class);
        $branding->saveDraft($owner, $companyId, $site, [
            'siteName' => 'Zargul Silver & Gold', 'tagline' => 'Events & celebrations',
            'themePreset' => 'ivory', 'fontPreset' => 'modern',
            'logoMediaId' => null, 'faviconMediaId' => null,
        ], 0, 'Owner proposed approved brand');
        $branding->publish($owner, $companyId, $site, 1, 'Owner approved brand');
    }

    public function test_safe_html_uses_only_published_snapshot_escaped_text_and_whitelisted_theme(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $reader = app(SitePublishedHtmlService::class);
        $this->assertNull($reader->render($company->id, $site, '/'));
        $this->brand($owner, $company->id, $site);
        $this->assertNull($reader->render($company->id, $site, '/'));

        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $page, ['sections' => [
            ['id' => 'welcome', 'type' => 'text',
                'data' => ['title' => 'Our Wedding & Event Hall', 'body' => 'Approved service & experience']],
            ['id' => 'book', 'type' => 'cta',
                'data' => [
                    'title' => 'Contact our team', 'buttonLabel' => 'Learn more',
                    'buttonHref' => 'https://example.com/enquire?event=1&guests=2',
                ]],
        ]], 0, 'Owner approved content');
        $pages->publish($owner, $company->id, $page, 1, 'First approved page');

        $pages->saveDraft($owner, $company->id, $page, ['sections' => [
            ['id' => 'welcome', 'type' => 'text', 'data' => ['title' => 'Not published yet']],
        ]], 1, 'New private draft');
        $html = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('Zargul Silver &amp; Gold', $html);
        $this->assertStringContainsString('content="Events &amp; celebrations"', $html);
        $this->assertStringContainsString('content="noindex, nofollow"', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('Official Home &amp; Venue', $html);
        $this->assertStringContainsString('Our Wedding &amp; Event Hall', $html);
        $this->assertStringContainsString('background: #fffdf5', $html);
        $this->assertStringContainsString('font-family: system-ui, sans-serif', $html);
        $this->assertStringContainsString('event=1&amp;guests=2', $html);
        $this->assertStringNotContainsString('Not published yet', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('site-media/private/', $html);
        $this->assertNull($reader->render((string) Str::uuid(), $site, '/'));
    }

    public function test_versioned_footer_is_not_visible_until_approved_and_legacy_branding_renders(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $this->brand($owner, $company->id, $site);
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $page, ['sections' => [[
            'id' => 'intro', 'type' => 'text', 'data' => ['title' => 'Approved home'],
        ]]], 0, 'Approved home content');
        $pages->publish($owner, $company->id, $page, 1, 'Publish approved home');

        $reader = app(SitePublishedHtmlService::class);
        $legacy = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('<p>Zargul Silver &amp; Gold</p>', $legacy);

        $brandingService = app(SiteBrandingService::class);
        $newDraft = $brandingService->publishedBranding($company->id, $site);
        $newDraft['footerText'] = 'Celebrations & treasured moments';
        $brandingService->saveDraft($owner, $company->id, $site, $newDraft, 1, 'Prepare short public footer');
        $this->assertSame($legacy, $reader->render($company->id, $site, '/'));
        $brandingService->publish($owner, $company->id, $site, 2, 'Approve public footer message');

        $approved = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('<p>Celebrations &amp; treasured moments</p>', $approved);
        $this->assertStringNotContainsString('<p>Zargul Silver &amp; Gold</p>', $approved);
        $this->assertStringNotContainsString('site-media/private/', $approved);
    }

    public function test_seo_description_is_approved_snapshot_only_and_legacy_tagline_remains_fallback(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $this->brand($owner, $company->id, $site);
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $page, ['sections' => [[
            'id' => 'intro', 'type' => 'text', 'data' => ['title' => 'Venue overview'],
        ]]], 0, 'Approve public page');
        $pages->publish($owner, $company->id, $page, 1, 'Publish page');
        $reader = app(SitePublishedHtmlService::class);

        $legacy = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('content="Events &amp; celebrations"', $legacy);
        $this->assertStringContainsString('content="noindex, nofollow"', $legacy);
        $this->assertStringContainsString('href="#main-content"', $legacy);
        $this->assertStringContainsString('Preview rendering only.', $legacy);

        $brand = app(SiteBrandingService::class);
        $draft = $brand->publishedBranding($company->id, $site);
        $draft['seoDescription'] = 'Special occasions & private celebrations';
        $brand->saveDraft($owner, $company->id, $site, $draft, 1, 'Propose safe search description');
        $this->assertSame($legacy, $reader->render($company->id, $site, '/'));
        $brand->publish($owner, $company->id, $site, 2, 'Approve search metadata');

        $published = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('content="Special occasions &amp; private celebrations"', $published);
        $this->assertStringNotContainsString('content="Events &amp; celebrations"', $published);
        $this->assertStringContainsString('content="noindex, nofollow"', $published);
        $this->assertStringNotContainsString('rel="canonical"', $published);
    }

    public function test_page_level_seo_title_is_versioned_escaped_and_hidden_before_publish(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $this->brand($owner, $company->id, $site);
        $pages = app(SitePagePublishingService::class);
        $content = ['sections' => [[
            'id' => 'intro', 'type' => 'text', 'data' => ['title' => 'Approved page body'],
        ]]];
        $pages->saveDraft($owner, $company->id, $page, $content, 0, 'Legacy safe page');
        $pages->publish($owner, $company->id, $page, 1, 'Publish legacy page');
        $reader = app(SitePublishedHtmlService::class);
        $legacy = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('<title>Official Home &amp; Venue | Zargul Silver &amp; Gold</title>', $legacy);

        $content['seoTitle'] = 'Private Weddings & Celebrations';
        $pages->saveDraft($owner, $company->id, $page, $content, 1, 'Prepare owner SEO title');
        $this->assertSame($legacy, $reader->render($company->id, $site, '/'));
        $pages->publish($owner, $company->id, $page, 2, 'Approve page search title');

        $published = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('<title>Private Weddings &amp; Celebrations</title>', $published);
        $this->assertStringNotContainsString('<title>Official Home &amp; Venue |', $published);
        $this->assertStringContainsString('content="noindex, nofollow"', $published);
        $this->assertStringNotContainsString('rel="canonical"', $published);
    }

    public function test_per_page_search_description_only_overrides_branding_after_explicit_publish(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $this->brand($owner, $company->id, $site);
        $pages = app(SitePagePublishingService::class);
        $content = ['sections' => []];
        $pages->saveDraft($owner, $company->id, $page, $content, 0, 'Publish legacy content');
        $pages->publish($owner, $company->id, $page, 1, 'Approve legacy content');
        $reader = app(SitePublishedHtmlService::class);
        $legacy = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('content="Events &amp; celebrations"', $legacy);
        $content['seoDescription'] = 'Weddings & receptions at the marquee';
        $pages->saveDraft($owner, $company->id, $page, $content, 1, 'Stage SEO summary override');
        $this->assertSame($legacy, $reader->render($company->id, $site, '/'));
        $pages->publish($owner, $company->id, $page, 2, 'Approve this page search description');
        $published = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('content="Weddings &amp; receptions at the marquee"', $published);
        $this->assertStringNotContainsString('content="Events &amp; celebrations"', $published);
        $this->assertStringContainsString('content="noindex, nofollow"', $published);
        $this->assertStringNotContainsString('property="og:title"', $published);
        $content['seoDescription'] = '';
        $pages->saveDraft($owner, $company->id, $page, $content, 2, 'Remove page search override');
        $this->assertSame($published, $reader->render($company->id, $site, '/'));
        $pages->publish($owner, $company->id, $page, 3, 'Restore venue-level fallback');
        $this->assertStringContainsString('content="Events &amp; celebrations"',
            $reader->render($company->id, $site, '/'));
    }

    public function test_responsive_mobile_order_and_approved_image_placeholder_do_not_expose_raw_media(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $this->brand($owner, $company->id, $site);
        $mediaService = app(SiteMediaService::class);
        $media = $mediaService->stageImage($owner, $company->id,
            UploadedFile::fake()->image('marquee.png', 350, 200),
            'Verified marquee hall', 'Approved hall photo upload');
        $mediaService->approveImage($owner, $company->id, $media, 'Owner approved hall photo');

        $content = ['sections' => [[
            'id' => 'row', 'type' => 'columns',
            'columns' => [
                ['id' => 'a', 'widthDesktop' => 6, 'mobileOrder' => 2, 'blocks' => [
                    ['id' => 'photo', 'type' => 'image', 'data' => ['imageId' => $media, 'alt' => 'Actual marquee hall']],
                ]],
                ['id' => 'b', 'widthDesktop' => 6, 'mobileOrder' => 1, 'blocks' => [
                    ['id' => 'text', 'type' => 'text', 'data' => ['title' => 'Start planning']],
                ]],
            ],
        ]]];
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $page, $content, 0, 'Create responsive columns');
        $pages->publish($owner, $company->id, $page, 1, 'Approve responsive page');

        $html = app(SitePublishedHtmlService::class)->render($company->id, $site, '/');
        $this->assertStringContainsString('--desktop-span: 6; --mobile-order: 2;', $html);
        $this->assertStringContainsString('--desktop-span: 6; --mobile-order: 1;', $html);
        $this->assertStringContainsString('Actual marquee hall', $html);
        $this->assertStringContainsString('Approved image placeholder', $html);
        $this->assertStringNotContainsString('site-media/private/', $html);
        $this->assertStringNotContainsString('<img ', $html);
        $this->assertStringNotContainsString('.png', $html);
    }
    public function test_phase10_layout_preset_and_hall_contacts_render_from_published_snapshot(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        DB::table('halls')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id,
            'name' => 'Garden Hall', 'slug' => 'garden-hall', 'capacity' => 180,
            'address' => '12 Mall Road, Lahore', 'phone' => '+92 300 1234567',
            'whatsapp' => '+92 300 7654321',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(SiteBrandingService::class)->saveDraft($owner, $company->id, $site, [
            'siteName' => 'Garden Events', 'tagline' => 'Celebrate outdoors',
            'themePreset' => 'sage', 'fontPreset' => 'modern', 'layoutPreset' => 'editorial',
            'logoMediaId' => null, 'faviconMediaId' => null,
        ], 0, 'Owner approved editorial layout');
        app(SiteBrandingService::class)->publish($owner, $company->id, $site, 1, 'Publish layout');
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $page, ['sections' => [[
            'id' => 'halls', 'type' => 'halls', 'data' => ['title' => 'Garden halls'],
        ]]], 0, 'Publish hall contacts');
        $pages->publish($owner, $company->id, $page, 1, 'Publish hall page');

        $html = app(SitePublishedHtmlService::class)->render($company->id, $site, '/');
        $this->assertStringContainsString('class="theme-editorial"', $html);
        $this->assertStringContainsString('Garden Hall', $html);
        $this->assertStringContainsString('12 Mall Road, Lahore', $html);
        $this->assertStringContainsString('google.com/maps/search', $html);
        $this->assertStringContainsString('+92 300 1234567', $html);
        $this->assertStringContainsString('https://wa.me/923007654321', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
    public function test_hall_contact_changes_require_a_new_branding_publication(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $hall = (string) Str::uuid();
        DB::table('halls')->insert([
            'id' => $hall, 'company_id' => $company->id,
            'name' => 'Published Hall', 'slug' => 'published-hall', 'capacity' => 120,
            'address' => 'Old approved address', 'phone' => '+92 300 1111111',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->brand($owner, $company->id, $site);
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $page, ['sections' => [[
            'id' => 'halls', 'type' => 'halls', 'data' => ['title' => 'Our halls'],
        ]]], 0, 'Publish hall contact baseline');
        $pages->publish($owner, $company->id, $page, 1, 'Publish hall contact page');

        $reader = app(SitePublishedHtmlService::class);
        $this->assertStringContainsString('Old approved address', $reader->render($company->id, $site, '/'));

        DB::table('halls')->where('id', $hall)->update([
            'address' => 'Unpublished changed address', 'phone' => '+92 300 9999999',
            'updated_at' => now(),
        ]);
        $unchanged = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('Old approved address', $unchanged);
        $this->assertStringNotContainsString('Unpublished changed address', $unchanged);

        $branding = app(SiteBrandingService::class);
        $branding->saveDraft($owner, $company->id, $site, [
            'siteName' => 'Zargul Silver & Gold', 'tagline' => 'Events & celebrations',
            'themePreset' => 'ivory', 'fontPreset' => 'modern',
            'logoMediaId' => null, 'faviconMediaId' => null,
        ], 1, 'Owner re-approves hall contact change');
        $branding->publish($owner, $company->id, $site, 2, 'Publish changed contacts');
        $updated = $reader->render($company->id, $site, '/');
        $this->assertStringContainsString('Unpublished changed address', $updated);
        $this->assertStringNotContainsString('Old approved address', $updated);
    }}
