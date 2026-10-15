<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SitePage;
use App\Models\SitePageRevision;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class SiteModelsScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_company_scopes_exclude_other_tenants_pages_and_versions(): void
    {
        $pages = [];
        $companies = [];
        foreach (['alpha', 'beta'] as $slug) {
            $company = Company::create(['name' => $slug, 'slug' => $slug]);
            $companies[] = $company;
            $owner = User::create([
                'name' => $slug, 'email' => $slug.'@example.test', 'password' => 'not-production',
            ]);
            $websiteId = (string) Str::uuid();
            $pageId = (string) Str::uuid();
            DB::table('websites')->insert(['id' => $websiteId, 'company_id' => $company->id, 'name' => $slug]);
            DB::table('site_pages')->insert(['id' => $pageId, 'company_id' => $company->id, 'website_id' => $websiteId, 'slug' => '/', 'title' => $slug]);
            DB::table('site_page_revisions')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company->id, 'page_id' => $pageId,
                'revision_number' => 1, 'content' => json_encode(['sections' => []]), 'created_by' => $owner->id,
            ]);
            $pages[] = $pageId;
        }

        $tenant = $companies[0]->id;
        $this->assertSame(1, Website::forCompany($tenant)->count());
        $this->assertSame([$pages[0]], SitePage::forCompany($tenant)->pluck('id')->all());
        $this->assertSame(1, SitePageRevision::forCompany($tenant)->count());
        $this->assertSame(0, SitePageRevision::forCompany($tenant)->where('page_id', $pages[1])->count());
        $this->assertSame('alpha', SitePage::forCompany($tenant)->firstOrFail()->website->name);
    }

    public function test_eloquent_history_rejects_update_and_delete_before_database_triggers(): void
    {
        $company = Company::create(['name' => 'alpha', 'slug' => 'alpha']);
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test']);
        $websiteId = (string) Str::uuid();
        $pageId = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $websiteId, 'company_id' => $company->id, 'name' => 'alpha']);
        DB::table('site_pages')->insert(['id' => $pageId, 'company_id' => $company->id, 'website_id' => $websiteId, 'slug' => '/', 'title' => 'Home']);
        $revisionId = (string) Str::uuid();
        DB::table('site_page_revisions')->insert([
            'id' => $revisionId, 'company_id' => $company->id, 'page_id' => $pageId,
            'revision_number' => 1, 'content' => json_encode(['sections' => []]), 'created_by' => $owner->id,
        ]);
        $revision = SitePageRevision::forCompany($company->id)->findOrFail($revisionId);
        $this->assertSame(['sections' => []], $revision->content);
        $revision->revision_number = 2;
        try {
            $revision->save();
            $this->fail('Eloquent overwrote immutable history');
        } catch (LogicException) {
            $this->assertSame(1, DB::table('site_page_revisions')->where('id', $revisionId)->value('revision_number'));
        }
        $this->expectException(LogicException::class);
        $revision->delete();
    }
}
