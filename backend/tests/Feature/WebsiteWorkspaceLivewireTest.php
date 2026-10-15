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

class WebsiteWorkspaceLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $canPublish): array
    {
        $company = Company::create(['name' => 'Silver Venue', 'slug' => 'silver']);
        $actor = User::create([
            'name' => 'Editor', 'email' => 'livewire-editor@example.test', 'password' => 'test-only',
        ]);
        $actor->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
            'role' => $canPublish ? 'owner' : 'manager',
            'can_edit_site' => ! $canPublish,
        ]);
        $websiteId = (string) Str::uuid();
        $pageId = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $websiteId, 'company_id' => $company->id, 'name' => 'Silver']);
        DB::table('site_pages')->insert([
            'id' => $pageId, 'company_id' => $company->id, 'website_id' => $websiteId,
            'slug' => '/', 'title' => 'Home',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($actor);

        return [$company, $actor, $pageId];
    }

    public function test_owner_edits_typed_blocks_and_publishes_saved_snapshot(): void
    {
        [$company, , $pageId] = $this->fixture(true);

        $component = Livewire::test(WebsiteWorkspace::class)
            ->assertSet('companyId', $company->id)
            ->assertSet('pageId', $pageId)
            ->call('addSection', 'text')
            ->set('sections.0.data.title', 'Our venue')
            ->set('reason', 'Write venue introduction')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSet('expectedRevision', 1);

        $this->assertSame(1, (int) DB::table('site_pages')->where('id', $pageId)->value('draft_revision'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.draft_saved')->count());

        $component->set('reason', 'Publish approved introduction')
            ->call('publishDraft')
            ->assertHasNoErrors();

        $this->assertNotNull(DB::table('site_pages')->where('id', $pageId)->value('published_revision_id'));
        $this->assertSame(1, DB::table('site_publishing_events')->where('action', 'publish')->count());
    }

    public function test_delegated_editor_has_draft_controls_but_no_publish_button(): void
    {
        [$company, , $pageId] = $this->fixture(false);

        Livewire::test(WebsiteWorkspace::class)
            ->assertSet('companyId', $company->id)
            ->assertSet('pageId', $pageId)
            ->assertSee('Save draft')
            ->assertDontSee('Publish saved draft')
            ->call('addSection', 'hero')
            ->set('reason', 'Prepare new banner')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame(1, (int) DB::table('site_pages')->where('id', $pageId)->value('draft_revision'));
        $this->assertSame(0, DB::table('site_publishing_events')->count());
    }

    public function test_unsaved_live_editor_changes_cannot_be_silently_published(): void
    {
        [, , $pageId] = $this->fixture(true);

        $component = Livewire::test(WebsiteWorkspace::class)
            ->call('addSection', 'text')
            ->set('reason', 'Create draft')
            ->call('saveDraft')
            ->assertHasNoErrors();
        $component->set('sections.0.data.title', 'Changed without saving')
            ->set('reason', 'Attempt publish')
            ->call('publishDraft')
            ->assertHasErrors(['sections']);

        $this->assertNull(DB::table('site_pages')->where('id', $pageId)->value('published_revision_id'));
    }
}
