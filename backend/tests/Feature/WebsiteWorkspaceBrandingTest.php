<?php

namespace Tests\Feature;

use App\Filament\Pages\WebsiteWorkspace;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteWorkspaceBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $publisher = true): array
    {
        $company = Company::create(['name' => 'Silver Venue', 'slug' => 'silver']);
        $actor = User::create([
            'name' => 'Operator', 'email' => 'branding-panel@example.test', 'password' => 'test-only',
        ]);
        $actor->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $actor->id,
            'role' => $publisher ? 'owner' : 'manager',
            'can_edit_site' => ! $publisher,
        ]);
        $siteId = (string) Str::uuid();
        $pageId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $siteId, 'company_id' => $company->id, 'name' => 'Silver Marquee',
        ]);
        DB::table('site_pages')->insert([
            'id' => $pageId, 'company_id' => $company->id,
            'website_id' => $siteId, 'slug' => '/', 'title' => 'Home',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($actor);

        return [$company, $actor, $siteId, $pageId];
    }

    public function test_owner_can_save_and_publish_branding_from_existing_website_tab(): void
    {
        [$company, , $site] = $this->fixture();
        $component = Livewire::test(WebsiteWorkspace::class)
            ->assertSet('companyId', $company->id)
            ->assertSet('expectedBrandingRevision', 0)
            ->assertSee('Publish branding')
            ->set('branding.siteName', 'Zargul Silver Marquee')
            ->set('branding.tagline', 'Wedding and event venue')
            ->set('branding.themePreset', 'ivory')
            ->set('brandingReason', 'Update approved company identity')
            ->call('saveBrandingDraft')
            ->assertHasNoErrors()
            ->assertSet('expectedBrandingRevision', 1);

        $this->assertNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
        $component->set('brandingReason', 'Owner approved current branding')
            ->call('publishBranding')->assertHasNoErrors();
        $this->assertNotNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
        $this->assertSame(1, DB::table('site_branding_publishing_events')->count());
        $this->assertSame(2, DB::table('audit_events')->count());
    }

    public function test_owner_cannot_publish_unsaved_branding_form_changes(): void
    {
        [, , $site] = $this->fixture();
        Livewire::test(WebsiteWorkspace::class)
            ->set('branding.siteName', 'Silver')
            ->set('brandingReason', 'Create branding draft')
            ->call('saveBrandingDraft')
            ->assertHasNoErrors()
            ->set('branding.siteName', 'Changed without saving')
            ->set('brandingReason', 'Attempt unsaved publication')
            ->call('publishBranding')
            ->assertHasErrors(['branding']);

        $this->assertNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
    }

    public function test_delegated_site_editor_can_save_branding_but_does_not_get_publish_control(): void
    {
        [, , $site] = $this->fixture(false);
        Livewire::test(WebsiteWorkspace::class)
            ->assertSee('Save branding draft')
            ->assertDontSee('Publish branding')
            ->set('branding.siteName', 'Editor proposed venue identity')
            ->set('brandingReason', 'Prepare owner review')
            ->call('saveBrandingDraft')
            ->assertHasNoErrors();

        $this->assertSame(1, (int) DB::table('websites')->where('id', $site)->value('branding_draft_revision'));
        $this->assertNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
    }
}
