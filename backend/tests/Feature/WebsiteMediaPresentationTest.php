<?php

namespace Tests\Feature;

use App\Filament\Pages\WebsiteWorkspace;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteMediaService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteMediaPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Silver', 'slug' => 'silver-media']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'media-presentation@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $website = (string) Str::uuid();
        $page = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $website, 'company_id' => $company->id, 'name' => 'Silver',
        ]);
        DB::table('site_pages')->insert([
            'id' => $page, 'company_id' => $company->id, 'website_id' => $website,
            'slug' => '/', 'title' => 'Home',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        return [$company, $owner, $website, $page];
    }

    private function stageApproved(User $owner, string $companyId): string
    {
        $service = app(SiteMediaService::class);
        $id = $service->stageImage(
            $owner, $companyId, UploadedFile::fake()->image('venue.png', 640, 480),
            'Approved Silver ballroom photograph', 'Stage a synthetic venue photograph',
        );
        $service->approveImage($owner, $companyId, $id, 'Owner approved synthetic venue photograph');

        return $id;
    }

    public function test_authenticated_company_media_thumbnail_route_is_private_and_returns_webp(): void
    {
        [$company, $owner] = $this->fixture();
        $id = $this->stageApproved($owner, $company->id);

        $response = $this->actingAs($owner)->get(route('site.media.preview', [
            'mediaId' => $id, 'variant' => 'thumb',
        ]));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('RIFF', $response->getContent());

        $stranger = User::create([
            'name' => 'Other owner', 'email' => 'other-media@example.test', 'password' => 'test-only',
        ]);
        $stranger->markEmailAsVerified();
        $other = Company::create(['name' => 'Other', 'slug' => 'other-media']);
        CompanyMembership::create([
            'company_id' => $other->id, 'user_id' => $stranger->id, 'role' => 'owner',
        ]);
        $this->actingAs($stranger)->get(route('site.media.preview', ['mediaId' => $id]))
            ->assertForbidden();

        auth()->logout();
        $unauthenticated = $this->get(route('site.media.preview', ['mediaId' => $id]));
        $this->assertContains($unauthenticated->getStatusCode(), [302, 401]);
    }

    public function test_replacement_creates_new_asset_and_retirement_preserves_published_revisions(): void
    {
        [$company, $owner, $website, $page] = $this->fixture();
        $asset = $this->stageApproved($owner, $company->id);
        $service = app(SiteMediaService::class);

        $revisionId = (string) Str::uuid();
        DB::table('site_page_revisions')->insert([
            'id' => $revisionId, 'company_id' => $company->id, 'page_id' => $page,
            'revision_number' => 1, 'created_by' => $owner->id,
            'content' => json_encode(['sections' => [[
                'id' => 'section_published', 'type' => 'image',
                'data' => ['imageId' => $asset, 'alt' => 'Published ballroom', 'caption' => ''],
            ]]], JSON_THROW_ON_ERROR),
        ]);
        DB::table('site_pages')->where('id', $page)->update([
            'published_revision_id' => $revisionId, 'draft_revision' => 1,
        ]);

        try {
            $service->retireImage($owner, $company->id, $asset, 'Try to retire a published image');
            $this->fail('Published revision lost its media asset.');
        } catch (InvalidArgumentException) {
            $this->assertSame('approved', DB::table('site_media')->where('id', $asset)->value('status'));
        }

        $replacement = $service->stageReplacement(
            $owner, $company->id, $asset,
            UploadedFile::fake()->image('venue-new.png', 640, 480),
            'Replacement Silver ballroom photograph',
            'Stage a safer replacement without changing published bytes',
        );
        $this->assertNotSame($asset, $replacement);
        $this->assertSame('approved', DB::table('site_media')->where('id', $asset)->value('status'));
        $this->assertSame('pending', DB::table('site_media')->where('id', $replacement)->value('status'));
        $metadata = json_decode(DB::table('site_media')->where('id', $replacement)->value('metadata'), true);
        $this->assertSame($asset, $metadata['replaced_from']);
        $published = json_decode(DB::table('site_page_revisions')->where('id', $revisionId)->value('content'), true);
        $this->assertSame($asset, $published['sections'][0]['data']['imageId']);

        $service->retireImage($owner, $company->id, $replacement, 'Retire unused replacement asset');
        $this->assertSame('retired', DB::table('site_media')->where('id', $replacement)->value('status'));
        $this->assertSame(2, DB::table('audit_events')->whereIn('action', [
            'website.media_replacement_staged', 'website.media_retired',
        ])->count());
    }

    public function test_owner_crop_and_focal_point_are_saved_in_the_draft_and_previewed(): void
    {
        [$company, $owner] = $this->fixture();
        $asset = $this->stageApproved($owner, $company->id);

        Livewire::test(WebsiteWorkspace::class)
            ->call('addImageFromLibrary', $asset)
            ->set('sections.0.data.focalX', '35')
            ->set('sections.0.data.focalY', '65')
            ->set('sections.0.data.cropAspect', '4:3')
            ->set('reason', 'Set the approved photograph focal point and crop')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSee('/site-media/'.$asset);

        $content = json_decode(DB::table('site_page_revisions')->where('company_id', $company->id)
            ->value('content'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('35', $content['sections'][0]['data']['focalX']);
        $this->assertSame('65', $content['sections'][0]['data']['focalY']);
        $this->assertSame('4:3', $content['sections'][0]['data']['cropAspect']);
    }
}
