<?php

namespace Tests\Feature;

use App\Filament\Pages\WebsiteWorkspace;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteWorkspaceMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_stages_approves_and_publishes_a_typed_image_block(): void
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Silver', 'slug' => 'silver']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'media-ui@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $websiteId = (string) Str::uuid();
        $pageId = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $websiteId, 'company_id' => $company->id, 'name' => 'Silver']);
        DB::table('site_pages')->insert([
            'id' => $pageId, 'company_id' => $company->id, 'website_id' => $websiteId,
            'slug' => '/', 'title' => 'Home',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $editor = Livewire::test(WebsiteWorkspace::class)
            ->set('pendingImage', UploadedFile::fake()->image('ballroom.png', 300, 200))
            ->set('mediaAltText', 'Approved Silver ballroom interior')
            ->set('mediaReason', 'Stage the official hall photograph')
            ->call('stageMedia')
            ->assertHasNoErrors();

        $asset = DB::table('site_media')->where('company_id', $company->id)->first();
        $this->assertNotNull($asset);
        $this->assertSame('pending', $asset->status);
        Storage::disk('local')->assertExists($asset->object_key);

        $editor->set('mediaReason', 'Owner checked the venue image')
            ->call('approveMedia', $asset->id)
            ->assertHasNoErrors()
            ->call('addImageFromLibrary', $asset->id)
            ->set('reason', 'Add approved image to draft')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->set('reason', 'Approve image on the public page')
            ->call('publishDraft')
            ->assertHasNoErrors();

        $content = json_decode(DB::table('site_page_revisions')
            ->where('page_id', $pageId)->value('content'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('image', $content['sections'][0]['type']);
        $this->assertSame($asset->id, $content['sections'][0]['data']['imageId']);
        $this->assertNotNull(DB::table('site_pages')->where('id', $pageId)->value('published_revision_id'));
        $this->assertSame('approved', DB::table('site_media')->where('id', $asset->id)->value('status'));
        $this->assertSame(4, DB::table('audit_events')->count());
    }
}
