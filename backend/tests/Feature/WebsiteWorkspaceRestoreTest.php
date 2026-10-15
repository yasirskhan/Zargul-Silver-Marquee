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

class WebsiteWorkspaceRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_restores_prior_published_version_and_preserves_new_draft(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'silver']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'restore-ui@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $website = (string) Str::uuid();
        $page = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $website, 'company_id' => $company->id, 'name' => 'Silver']);
        DB::table('site_pages')->insert([
            'id' => $page, 'company_id' => $company->id, 'website_id' => $website,
            'slug' => '/', 'title' => 'Home',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        $editor = Livewire::test(WebsiteWorkspace::class)
            ->call('addSection', 'text')
            ->set('sections.0.data.title', 'First version')
            ->set('reason', 'Start home page')->call('saveDraft')->assertHasNoErrors()
            ->set('reason', 'Publish first')->call('publishDraft')->assertHasNoErrors();
        $first = DB::table('site_pages')->where('id', $page)->value('published_revision_id');

        $editor->set('sections.0.data.title', 'Second version')
            ->set('reason', 'Revise headline')->call('saveDraft')->assertHasNoErrors()
            ->set('reason', 'Publish second')->call('publishDraft')->assertHasNoErrors();
        $second = DB::table('site_pages')->where('id', $page)->value('published_revision_id');
        $this->assertNotSame($first, $second);

        $editor->set('sections.0.data.title', 'Third draft, not published')
            ->set('reason', 'Draft additional changes')->call('saveDraft')->assertHasNoErrors()
            ->set('reason', 'Restore the original approved version')
            ->call('restorePublishedRevision', $first)->assertHasNoErrors();

        $this->assertSame($first, DB::table('site_pages')->where('id', $page)->value('published_revision_id'));
        $this->assertSame(3, (int) DB::table('site_pages')->where('id', $page)->value('draft_revision'));
        $this->assertSame(3, DB::table('site_page_revisions')->where('page_id', $page)->count());
        $this->assertSame(1, DB::table('site_publishing_events')->where('action', 'restore')->count());
        $this->assertSame(6, DB::table('audit_events')->count());
    }
}
