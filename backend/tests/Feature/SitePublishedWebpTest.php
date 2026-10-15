<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteBrandingService;
use App\Services\SiteMediaService;
use App\Services\SitePagePublishingService;
use App\Services\SitePublishedWebpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class SitePublishedWebpTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        Storage::fake('public');
        $company = Company::create(['name' => 'WebP Venue', 'slug' => 'webp-venue']);
        $owner = User::create(['name' => 'Owner', 'email' => 'webp-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $site = (string) Str::uuid();
        $page = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $site, 'company_id' => $company->id, 'name' => 'WebP Venue']);
        DB::table('site_pages')->insert([
            'id' => $page, 'company_id' => $company->id,
            'website_id' => $site, 'slug' => '/', 'title' => 'Home',
        ]);
        $this->be($owner);

        return [$company, $owner, $site, $page];
    }

    private function uploadApproved(User $owner, string $companyId): string
    {
        $service = app(SiteMediaService::class);
        $media = $service->stageImage($owner, $companyId,
            UploadedFile::fake()->image('ballroom.png', 300, 200),
            'Verified real venue image', 'Owner-supplied venue photograph');
        $service->approveImage($owner, $companyId, $media, 'Publisher approved actual photograph');

        return $media;
    }

    private function publish(User $owner, string $companyId, string $site, string $page, string $media): void
    {
        $branding = app(SiteBrandingService::class);
        $branding->saveDraft($owner, $companyId, $site, [
            'siteName' => 'WebP Venue', 'tagline' => '',
            'themePreset' => 'silver', 'fontPreset' => 'classic',
            'logoMediaId' => null, 'faviconMediaId' => null,
        ], 0, 'Approved original brand');
        $branding->publish($owner, $companyId, $site, 1, 'Owner approved branding');
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $companyId, $page, [
            'sections' => [
                ['id' => 'hero_photo', 'type' => 'image',
                    'data' => ['imageId' => $media, 'alt' => 'The actual marquee hall']],
            ],
        ], 0, 'Official image selection');
        $pages->publish($owner, $companyId, $page, 1, 'Owner approved actual image');
    }

    public function test_only_published_optimized_webp_bytes_are_read_without_public_disk_writes(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $media = $this->uploadApproved($owner, $company->id);
        $this->publish($owner, $company->id, $site, $page, $media);

        $result = app(SitePublishedWebpService::class)->read($company->id, $site, '/', $media);
        $this->assertSame('image/webp', $result['content_type']);
        $this->assertSame(300, $result['width']);
        $this->assertSame(200, $result['height']);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($result['bytes']));
        $this->assertSame('"'.hash('sha256', $result['bytes']).'"', $result['etag']);
        $this->assertSame(strlen($result['bytes']), $result['length']);
        $this->assertArrayNotHasKey('object_key', $result);
        $this->assertArrayNotHasKey('path', $result);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull(app(SitePublishedWebpService::class)
            ->read((string) Str::uuid(), $site, '/', $media));
        $this->assertNull(app(SitePublishedWebpService::class)
            ->read($company->id, (string) Str::uuid(), '/', $media));
        $this->assertNull(app(SitePublishedWebpService::class)
            ->read($company->id, $site, '/nonexistent', $media));
    }

    public function test_unpublished_or_pending_media_can_never_be_read(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $published = $this->uploadApproved($owner, $company->id);
        $otherApproved = $this->uploadApproved($owner, $company->id);
        $this->publish($owner, $company->id, $site, $page, $published);
        $service = app(SiteMediaService::class);
        $pending = $service->stageImage($owner, $company->id,
            UploadedFile::fake()->image('not-approved.jpg'),
            'Pending hall image', 'Proposed future gallery image');
        $reader = app(SitePublishedWebpService::class);
        $this->assertNull($reader->read($company->id, $site, '/', $otherApproved));
        $this->assertNull($reader->read($company->id, $site, '/', $pending));
        $this->assertNull($reader->read($company->id, $site, '/', 'not-a-uuid'));

        // An unpublished draft can reference pending media, without making it public.
        app(SitePagePublishingService::class)->saveDraft($owner, $company->id, $page, [
            'sections' => [
                ['id' => 'next', 'type' => 'image',
                    'data' => ['imageId' => $pending, 'alt' => 'Not approved yet']],
            ],
        ], 1, 'Owner preparing a draft with pending image');
        $this->assertNull($reader->read($company->id, $site, '/', $pending));
        $this->assertSame('image/webp', $reader->read($company->id, $site, '/', $published)['content_type']);
    }

    public function test_tampered_or_cross_company_variant_references_fail_closed(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $media = $this->uploadApproved($owner, $company->id);
        $this->publish($owner, $company->id, $site, $page, $media);

        $metadata = json_decode(DB::table('site_media')->where('id', $media)->value('metadata'), true);
        $path = $metadata['variants']['webp1200']['object_key'];
        Storage::disk('local')->put($path, 'not a WebP anymore');
        $this->expectException(InvalidArgumentException::class);
        app(SitePublishedWebpService::class)->read($company->id, $site, '/', $media);
    }

    public function test_variant_metadata_cannot_point_into_another_company_private_directory(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $media = $this->uploadApproved($owner, $company->id);
        $this->publish($owner, $company->id, $site, $page, $media);
        $meta = json_decode(DB::table('site_media')->where('id', $media)->value('metadata'), true);
        $meta['variants']['webp1200']['object_key'] = 'site-media/private/'.
            Str::uuid().'/'.Str::uuid().'-webp1200.webp';
        DB::table('site_media')->where('id', $media)->update(['metadata' => json_encode($meta, JSON_THROW_ON_ERROR)]);

        $this->expectException(InvalidArgumentException::class);
        app(SitePublishedWebpService::class)->read($company->id, $site, '/', $media);
    }
}
