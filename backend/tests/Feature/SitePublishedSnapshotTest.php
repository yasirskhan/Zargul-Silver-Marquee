<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteBrandingService;
use App\Services\SiteMediaService;
use App\Services\SitePagePublishingService;
use App\Services\SitePublishedSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class SitePublishedSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Silver Public', 'slug' => 'silver-public']);
        $owner = User::create(['name' => 'Owner', 'email' => 'public-projector@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $websiteId = (string) Str::uuid();
        $pageId = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $websiteId, 'company_id' => $company->id, 'name' => 'Silver Public']);
        DB::table('site_pages')->insert([
            'id' => $pageId, 'company_id' => $company->id,
            'website_id' => $websiteId, 'slug' => '/', 'title' => 'Home',
        ]);
        $this->be($owner);

        return [$company, $owner, $websiteId, $pageId];
    }

    private function branding(string $name, ?string $logo = null): array
    {
        return [
            'siteName' => $name, 'tagline' => 'Real celebration venue',
            'themePreset' => 'silver', 'fontPreset' => 'classic',
            'logoMediaId' => $logo, 'faviconMediaId' => null,
        ];
    }

    private function content(string $title, ?string $imageId = null): array
    {
        $sections = [['id' => 'headline', 'type' => 'text', 'data' => ['title' => $title]]];
        if ($imageId !== null) {
            $sections[] = ['id' => 'photo', 'type' => 'image',
                'data' => ['imageId' => $imageId, 'alt' => 'Approved ballroom image']];
        }

        return ['sections' => $sections];
    }

    public function test_projector_requires_both_approved_snapshots_and_never_reads_new_drafts(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $projector = app(SitePublishedSnapshotService::class);
        $branding = app(SiteBrandingService::class);
        $pages = app(SitePagePublishingService::class);
        $this->assertNull($projector->project($company->id, $site, '/'));

        $branding->saveDraft($owner, $company->id, $site, $this->branding('Approved original'), 0, 'First brand draft');
        $branding->publish($owner, $company->id, $site, 1, 'Approve original identity');
        $this->assertNull($projector->project($company->id, $site, '/'));

        $pages->saveDraft($owner, $company->id, $page, $this->content('Approved hall title'), 0, 'First page');
        $pages->publish($owner, $company->id, $page, 1, 'Approve first page');

        $branding->saveDraft($owner, $company->id, $site, $this->branding('Never published identity'), 1, 'Unpublished next identity');
        $pages->saveDraft($owner, $company->id, $page, $this->content('Never published headline'), 1, 'Unpublished next page');
        $projection = $projector->project($company->id, $site, '/');
        $this->assertSame('Approved original', $projection['branding']['siteName']);
        $this->assertSame('Approved hall title', $projection['page']['sections'][0]['data']['title']);
        $this->assertSame([], $projection['media']);
        $this->assertStringNotContainsString('Never published', json_encode($projection, JSON_THROW_ON_ERROR));
        $this->assertNull($projector->project((string) Str::uuid(), $site, '/'));
        $this->assertNull($projector->project($company->id, (string) Str::uuid(), '/'));
        $this->assertNull($projector->project($company->id, $site, '/not-a-page'));

        DB::table('websites')->where('id', $site)->update(['is_active' => false]);
        $this->assertNull($projector->project($company->id, $site, '/'));
    }

    public function test_only_intact_approved_webp_metadata_is_projected_without_storage_paths_or_original_bytes(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $mediaId = app(SiteMediaService::class)->stageImage($owner, $company->id,
            UploadedFile::fake()->image('ballroom.png', 240, 120),
            'Actual venue ballroom', 'Upload authentic venue photo');
        app(SiteMediaService::class)->approveImage($owner, $company->id, $mediaId, 'Owner reviewed photo');
        $brand = app(SiteBrandingService::class);
        $brand->saveDraft($owner, $company->id, $site, $this->branding('Silver Venue', $mediaId), 0, 'Approved logo draft');
        $brand->publish($owner, $company->id, $site, 1, 'Approve venue logo');
        $pageService = app(SitePagePublishingService::class);
        $pageService->saveDraft($owner, $company->id, $page, $this->content('Our hall', $mediaId), 0, 'Image section');
        $pageService->publish($owner, $company->id, $page, 1, 'Approve hall image');

        $projector = app(SitePublishedSnapshotService::class);
        $projection = $projector->project($company->id, $site, '/');
        $this->assertCount(1, $projection['media']);
        $this->assertSame('image/webp', $projection['media'][$mediaId]['mime']);
        $this->assertSame(240, $projection['media'][$mediaId]['width']);
        $this->assertSame('Actual venue ballroom', $projection['media'][$mediaId]['alt']);
        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('site-media/private/', $encoded);
        $this->assertStringNotContainsString('object_key', $encoded);
        $this->assertStringNotContainsString('.png', $encoded);
        $this->assertSame(6, DB::table('audit_events')->count());

        $meta = json_decode(DB::table('site_media')->where('id', $mediaId)->value('metadata'), true);
        Storage::disk('local')->put($meta['variants']['webp1200']['object_key'], 'altered bytes');
        $this->expectException(InvalidArgumentException::class);
        $projector->project($company->id, $site, '/');
    }

    public function test_revoked_approval_fails_closed_and_never_returns_staged_photo(): void
    {
        [$company, $owner, $site, $page] = $this->fixture();
        $mediaId = app(SiteMediaService::class)->stageImage($owner, $company->id,
            UploadedFile::fake()->image('ballroom.png', 180, 90),
            'Approved ballroom', 'Upload venue photo');
        app(SiteMediaService::class)->approveImage($owner, $company->id, $mediaId, 'Approve venue photo');
        $brand = app(SiteBrandingService::class);
        $brand->saveDraft($owner, $company->id, $site, $this->branding('Approved venue'), 0, 'New branding');
        $brand->publish($owner, $company->id, $site, 1, 'Approve branding');
        $pages = app(SitePagePublishingService::class);
        $pages->saveDraft($owner, $company->id, $page, $this->content('Hall', $mediaId), 0, 'Hall photo');
        $pages->publish($owner, $company->id, $page, 1, 'Approve hall photo');

        DB::table('site_media')->where('id', $mediaId)->update(['status' => 'pending']);
        $this->expectException(InvalidArgumentException::class);
        app(SitePublishedSnapshotService::class)->project($company->id, $site, '/');
    }
}
