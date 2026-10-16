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
            ->set('branding.footerText', 'Celebrate moments at Zargul Silver Marquee')
            ->set('branding.themePreset', 'ivory')
            ->set('brandingReason', 'Update approved company identity')
            ->call('saveBrandingDraft')
            ->assertHasNoErrors()
            ->assertSet('expectedBrandingRevision', 1);

        $this->assertNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
        $component->set('brandingReason', 'Owner approved current branding')
            ->call('publishBranding')->assertHasNoErrors();
        $this->assertNotNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
        $publishedId = DB::table('websites')->where('id', $site)->value('branding_published_revision_id');
        $published = json_decode(DB::table('site_branding_revisions')->where('id', $publishedId)->value('content'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Celebrate moments at Zargul Silver Marquee', $published['footerText']);
        $this->assertSame(1, DB::table('site_branding_publishing_events')->count());
        $this->assertSame(2, DB::table('audit_events')->count());
    }

    public function test_authorized_owner_can_hide_and_reorder_draft_menu_without_publishing_it(): void
    {
        [, , $site] = $this->fixture();
        $editor = Livewire::test(WebsiteWorkspace::class)
            ->assertSee('Website menu')
            ->call('toggleNavigationPath', '/gallery')
            ->call('moveNavigationPath', '/about', 'up')
            ->set('brandingReason', 'Owner proposes shorter venue navigation')
            ->call('saveBrandingDraft')->assertHasNoErrors();

        $revision = DB::table('site_branding_revisions')->where('website_id', $site)
            ->where('revision_number', 1)->value('content');
        $paths = json_decode($revision, true, 512, JSON_THROW_ON_ERROR)['navigationOrder'];
        $this->assertNotContains('/gallery', $paths);
        $this->assertContains('/', $paths);
        $this->assertLessThan(array_search('/services', $paths, true),
            array_search('/about', $paths, true));
        $this->assertNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
        $editor->assertSet('expectedBrandingRevision', 1);
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
    public function test_owner_restores_an_earlier_approved_brand_without_discarding_latest_draft(): void
    {
        [, , $site] = $this->fixture();
        $editor = Livewire::test(WebsiteWorkspace::class)
            ->set('branding.siteName', 'Original Silver')
            ->set('brandingReason', 'Initial approved branding')
            ->call('saveBrandingDraft')->assertHasNoErrors()
            ->set('brandingReason', 'Owner publishes original')
            ->call('publishBranding')->assertHasNoErrors();
        $first = DB::table('websites')->where('id', $site)->value('branding_published_revision_id');

        $editor->set('branding.siteName', 'Second Silver')
            ->set('brandingReason', 'Second approved branding')
            ->call('saveBrandingDraft')->assertHasNoErrors()
            ->set('brandingReason', 'Publish second version')
            ->call('publishBranding')->assertHasNoErrors();
        $editor->set('branding.siteName', 'Latest unsent draft')
            ->set('brandingReason', 'Work in progress')
            ->call('saveBrandingDraft')->assertHasNoErrors()
            ->assertSee('Restore branding revision 1')
            ->set('brandingReason', 'Restore approved original branding')
            ->call('restoreBranding', $first)->assertHasNoErrors();

        $this->assertSame($first, DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
        $this->assertSame(3, (int) DB::table('websites')->where('id', $site)->value('branding_draft_revision'));
        $this->assertSame(3, DB::table('site_branding_revisions')->count());
        $this->assertSame(1, DB::table('site_branding_publishing_events')->where('action', 'restore')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.branding_restored')->count());
        $editor->assertSet('branding.siteName', 'Latest unsent draft');
    }

    public function test_branding_preview_is_responsive_escaped_and_never_publishes_unsaved_changes(): void
    {
        [, , $site] = $this->fixture();
        $component = Livewire::test(WebsiteWorkspace::class)
            ->assertSee('Private branding draft preview')
            ->assertSee('Logo preview placeholder')
            ->set('branding.siteName', 'Private Draft Marquee')
            ->set('branding.tagline', '<img src=x onerror=alert(1)>')
            ->set('branding.themePreset', 'midnight')
            ->set('branding.fontPreset', 'modern')
            ->call('setPreviewMode', 'phone')
            ->assertSee('Phone branding')
            ->assertSee('Private Draft Marquee')
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSee('max-width: 360px', false)
            ->assertSee('background-color: #111827', false)
            ->assertSee('font-family: system-ui, sans-serif', false)
            ->assertDontSee('site-media/private/', false);

        $this->assertSame(0, (int) DB::table('websites')->where('id', $site)->value('branding_draft_revision'));
        $this->assertNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
        $this->assertSame(0, DB::table('audit_events')->count());
        $component->set('branding.themePreset', 'url(javascript:alert(1))')
            ->assertSee('background-color: #f3f4f6', false)
            ->assertDontSee('url(javascript:alert(1))', false);
    }

    public function test_delegated_editor_can_preview_changes_without_publisher_privileges(): void
    {
        $this->fixture(false);
        Livewire::test(WebsiteWorkspace::class)
            ->set('branding.siteName', 'Editor Work In Progress')
            ->call('setPreviewMode', 'tablet')
            ->assertSee('Editor Work In Progress')
            ->assertSee('max-width: 760px', false)
            ->assertDontSee('Publish branding');
    }

}
