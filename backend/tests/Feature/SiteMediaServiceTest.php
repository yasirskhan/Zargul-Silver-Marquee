<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteMediaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class SiteMediaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Silver', 'slug' => 'silver']);
        $owner = User::create(['name' => 'Owner', 'email' => 'media-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $websiteId = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $websiteId, 'company_id' => $company->id, 'name' => 'Silver']);
        $this->be($owner);

        return [$company, $owner];
    }

    public function test_private_image_is_staged_pending_then_publisher_approves_with_immutable_audit(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(SiteMediaService::class);
        $mediaId = $service->stageImage($owner, $company->id,
            UploadedFile::fake()->image('ballroom.png', 300, 200),
            'Silver ballroom interior', 'New approved venue photograph');

        $asset = DB::table('site_media')->where('id', $mediaId)->first();
        $this->assertSame('pending', $asset->status);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertSame('Silver ballroom interior', $asset->alt_text);
        $this->assertStringStartsWith('site-media/private/'.$company->id.'/', $asset->object_key);
        Storage::disk('local')->assertExists($asset->object_key);

        $service->approveImage($owner, $company->id, $mediaId, 'Owner reviewed this hall photo');
        $optimized = json_decode(DB::table('site_media')->where('id', $mediaId)->value('metadata'), true);
        $variant = $optimized['variants']['webp1200'];
        $this->assertSame('image/webp', $variant['mime_type']);
        $this->assertSame(300, $variant['width']);
        $this->assertSame(200, $variant['height']);
        $this->assertStringStartsWith('site-media/private/'.$company->id.'/', $variant['object_key']);
        Storage::disk('local')->assertExists($variant['object_key']);
        $this->assertSame($variant['sha256'], hash('sha256', Storage::disk('local')->get($variant['object_key'])));
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer(Storage::disk('local')->get($variant['object_key'])));
        $this->assertFalse(Storage::disk('public')->exists($variant['object_key']));

        $service->approveImage($owner, $company->id, $mediaId, 'Idempotent retry');
        $this->assertSame('approved', DB::table('site_media')->where('id', $mediaId)->value('status'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.media_staged')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.media_approved')->count());
    }

    public function test_delegated_editor_cannot_approve_image_and_other_company_cannot_approve(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(SiteMediaService::class);
        $mediaId = $service->stageImage($owner, $company->id,
            UploadedFile::fake()->image('hall.jpg'), 'Hall', 'Initial media');

        $editor = User::create(['name' => 'Editor', 'email' => 'media-editor@example.test', 'password' => 'test-only']);
        $editor->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $editor->id,
            'role' => 'manager', 'can_edit_site' => true,
        ]);
        $this->be($editor);
        try {
            $service->approveImage($editor, $company->id, $mediaId, 'Try unauthorized approval');
            $this->fail('Website editor may not approve media.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', DB::table('site_media')->where('id', $mediaId)->value('status'));
        }

        $other = Company::create(['name' => 'Other', 'slug' => 'other']);
        $this->be($owner);
        $this->expectException(AuthorizationException::class);
        $service->approveImage($owner, $other->id, $mediaId, 'Cross-company attempt');
    }

    public function test_fake_image_payload_is_rejected_without_database_or_disk_changes(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(SiteMediaService::class);
        $this->expectException(InvalidArgumentException::class);
        try {
            $service->stageImage($owner, $company->id,
                UploadedFile::fake()->createWithContent('malicious.png', '<script>fake image</script>'),
                'Fake photo', 'Should be rejected');
        } finally {
            $this->assertSame(0, DB::table('site_media')->count());
            $this->assertSame(0, DB::table('audit_events')->count());
        }
    }

    public function test_unverified_owner_and_inactive_company_cannot_stage_images(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(SiteMediaService::class);
        $owner->forceFill(['email_verified_at' => null])->save();
        try {
            $service->stageImage($owner, $company->id, UploadedFile::fake()->image('photo.jpg'),
                'Hall', 'Not verified');
            $this->fail('Unverified staff must not stage media');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('site_media')->count());
        }
        $owner->markEmailAsVerified();
        $company->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $service->stageImage($owner, $company->id, UploadedFile::fake()->image('photo.jpg'),
            'Hall', 'Inactive company');
    }
    public function test_changed_staged_file_cannot_be_approved_or_generate_a_variant(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(SiteMediaService::class);
        $mediaId = $service->stageImage($owner, $company->id,
            UploadedFile::fake()->image('hall.png', 200, 100), 'Hall', 'Upload original image');
        $asset = DB::table('site_media')->where('id', $mediaId)->first();

        Storage::disk('local')->put($asset->object_key, 'modified file');
        try {
            $service->approveImage($owner, $company->id, $mediaId, 'Approve original image');
            $this->fail('Modified staged content must not receive approval.');
        } catch (InvalidArgumentException) {
            $this->assertSame('pending', DB::table('site_media')->where('id', $mediaId)->value('status'));
            $this->assertSame(0, DB::table('audit_events')->where('action', 'website.media_approved')->count());
            $metadata = json_decode(DB::table('site_media')->where('id', $mediaId)->value('metadata'), true);
            $this->assertArrayNotHasKey('variants', $metadata);
        }
    }

    public function test_large_images_are_downscaled_to_private_webp_without_upscaling(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(SiteMediaService::class);
        $mediaId = $service->stageImage($owner, $company->id,
            UploadedFile::fake()->image('hall.jpg', 1800, 900),
            'Venue hall', 'Official photograph');
        $service->approveImage($owner, $company->id, $mediaId, 'Owner approves optimized image');

        $metadata = json_decode(DB::table('site_media')->where('id', $mediaId)->value('metadata'), true);
        $variant = $metadata['variants']['webp1200'];
        $this->assertSame(1200, $variant['width']);
        $this->assertSame(600, $variant['height']);
        Storage::disk('local')->assertExists($variant['object_key']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.media_approved')->count());
    }

}
