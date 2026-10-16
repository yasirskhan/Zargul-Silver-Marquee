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
}
