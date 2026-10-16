<?php

namespace Tests\Feature;

use App\Filament\Pages\WebsiteWorkspace;
use App\Services\SiteWorkspaceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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

    public function test_already_open_editor_loses_draft_save_after_delegation_is_revoked(): void
    {
        [$company, $editor, $pageId] = $this->fixture(false);
        $mountedEditor = Livewire::test(WebsiteWorkspace::class)
            ->call('addSection', 'text')
            ->set('sections.0.data.title', 'Unapproved post-revocation draft')
            ->set('reason', 'Old editor tab must be blocked');

        DB::table('company_memberships')
            ->where('company_id', $company->id)->where('user_id', $editor->id)
            ->update(['can_edit_site' => false]);

        // Invoke exactly one Livewire request after revocation. A separate
        // ->set() request would already deny hydration and invalidate this
        // test component before the actual action can be asserted.
        $mountedEditor->call('saveDraft')->assertForbidden();

        $this->assertSame(0, (int) DB::table('site_pages')->where('id', $pageId)->value('draft_revision'));
        $this->assertSame(0, DB::table('site_page_revisions')->where('page_id', $pageId)->count());
        $this->assertSame(0, DB::table('audit_events')->where('entity_id', $pageId)->count());
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
    public function test_owner_creates_unpublished_page_then_saves_and_publishes(): void
    {
        [$company, , $homeId] = $this->fixture(true);
        $component = Livewire::test(WebsiteWorkspace::class)
            ->assertSee('Create a new website page')
            ->set('newPageSlug', 'halls')->set('newPageTitle', 'Halls and packages')
            ->set('newPageReason', 'Prepare private venue page')
            ->call('createNewPage')->assertHasNoErrors()->assertSee('Halls and packages');
        $created = DB::table('site_pages')->where('company_id', $company->id)
            ->where('slug', '/halls')->first();
        $this->assertNotNull($created);
        $this->assertNotSame($homeId, $created->id);
        $this->assertNull($created->published_revision_id);
        $this->assertSame(0, (int) $created->draft_revision);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.page_created')->count());
        $component->assertSet('pageId', $created->id)->call('addSection', 'text')
            ->set('sections.0.data.title', 'Our hall')
            ->set('reason', 'Prepare hall information')->call('saveDraft')->assertHasNoErrors();
        $this->assertNull(DB::table('site_pages')->where('id', $created->id)->value('published_revision_id'));
        $component->set('reason', 'Approve new hall page')
            ->call('publishDraft')->assertHasNoErrors();
        $this->assertNotNull(DB::table('site_pages')->where('id', $created->id)->value('published_revision_id'));
    }

    public function test_page_creation_retry_and_path_conflicts_are_enforced(): void
    {
        [$company, $actor, $homeId] = $this->fixture(true);
        $site = (string) DB::table('site_pages')->where('id', $homeId)->value('website_id');
        $service = app(SiteWorkspaceService::class);
        $id = (string) Str::uuid();
        $this->assertSame($id, $service->createPage($actor, $company->id, $site,
            $id, '/gallery', 'Verified gallery', 'Private draft first'));
        $this->assertSame($id, $service->createPage($actor, $company->id, $site,
            $id, '/gallery', 'Verified gallery', 'Private draft first'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.page_created')->count());
        foreach ([
            [$id, 'Different gallery', 'Private draft first'],
            [$id, 'Verified gallery', 'Different reason'],
            [(string) Str::uuid(), 'Another gallery', 'Fresh request'],
        ] as [$key, $title, $reason]) {
            try {
                $service->createPage($actor, $company->id, $site, $key,
                    '/gallery', $title, $reason);
                $this->fail('Conflicting page creation was accepted.');
            } catch (ConflictHttpException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(2, DB::table('site_pages')->where('website_id', $site)->count());
        $this->expectException(InvalidArgumentException::class);
        $service->createPage($actor, $company->id, $site, (string) Str::uuid(),
            '/admin', 'Forbidden page', 'No management paths on public site');
    }

    public function test_site_editor_can_create_but_revoked_or_cross_company_cannot(): void
    {
        [$company, $actor, $homeId] = $this->fixture(false);
        $site = (string) DB::table('site_pages')->where('id', $homeId)->value('website_id');
        Livewire::test(WebsiteWorkspace::class)->assertDontSee('Publish saved draft')
            ->set('newPageSlug', 'about')->set('newPageTitle', 'About this venue')
            ->set('newPageReason', 'Draft private introduction')
            ->call('createNewPage')->assertHasNoErrors();
        $foreign = Company::create(['name' => 'Other Venue', 'slug' => 'other-venue']);
        try {
            app(SiteWorkspaceService::class)->createPage($actor, $foreign->id, $site,
                (string) Str::uuid(), '/contact', 'Foreign contact page', 'Unauthorized company');
            $this->fail('Foreign company creation was accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        DB::table('company_memberships')->where('company_id', $company->id)
            ->where('user_id', $actor->id)->update(['is_active' => false]);
        Livewire::test(WebsiteWorkspace::class)->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(SiteWorkspaceService::class)->createPage($actor, $company->id, $site,
            (string) Str::uuid(), '/contact', 'Revoked page', 'Revoked editor blocked');
    }

    public function test_failed_audit_insert_rolls_back_created_page(): void
    {
        [$company, $actor, $homeId] = $this->fixture(true);
        $site = (string) DB::table('site_pages')->where('id', $homeId)->value('website_id');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_new_site_page_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $blocked$
BEGIN
    IF NEW.action = 'website.page_created' THEN
        RAISE EXCEPTION 'Injected page creation audit failure';
    END IF;
    RETURN NEW;
END;
$blocked$;
SQL);
        DB::statement('CREATE TRIGGER reject_new_site_page_audit_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION reject_new_site_page_audit_test()');
        try {
            app(SiteWorkspaceService::class)->createPage($actor, $company->id, $site,
                (string) Str::uuid(), '/terms', 'Terms draft', 'Require audited page create');
            $this->fail('Page persisted despite audit rejection.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected page creation audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_new_site_page_audit_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS reject_new_site_page_audit_test()');
        }
        $this->assertSame(1, DB::table('site_pages')->where('website_id', $site)->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'website.page_created')->count());
    }

}
