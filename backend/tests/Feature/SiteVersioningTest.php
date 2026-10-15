<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteVersioningTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $prefix = 'zargul'): array
    {
        $company = Company::create(['slug' => $prefix, 'name' => $prefix]);
        $user = User::create([
            'name' => 'Editor',
            'email' => $prefix.'@example.test',
            'password' => 'test-only-not-production',
        ]);
        $websiteId = (string) Str::uuid();
        $pageId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $websiteId,
            'company_id' => $company->id,
            'name' => 'Zargul Silver Marquee',
        ]);
        DB::table('site_pages')->insert([
            'id' => $pageId,
            'website_id' => $websiteId,
            'company_id' => $company->id,
            'slug' => '/',
            'title' => 'Home',
        ]);

        return [$company, $user, $websiteId, $pageId];
    }

    private function revision(Company $company, User $user, string $pageId, int $number): string
    {
        $id = (string) Str::uuid();
        DB::table('site_page_revisions')->insert([
            'id' => $id,
            'company_id' => $company->id,
            'page_id' => $pageId,
            'revision_number' => $number,
            'content' => json_encode(['sections' => [['type' => 'hero', 'title' => 'Welcome v'.$number]]]),
            'created_by' => $user->id,
        ]);

        return $id;
    }

    public function test_draft_revision_cannot_change_currently_published_snapshot(): void
    {
        [$company, $user, , $pageId] = $this->fixture();
        $published = $this->revision($company, $user, $pageId, 1);
        DB::table('site_pages')->where('id', $pageId)->update([
            'draft_revision' => 1, 'published_revision_id' => $published,
        ]);

        $this->revision($company, $user, $pageId, 2);
        DB::table('site_pages')->where('id', $pageId)->update(['draft_revision' => 2]);

        $page = DB::table('site_pages')->where('id', $pageId)->first();
        $this->assertSame($published, $page->published_revision_id);
        $this->assertSame(2, $page->draft_revision);
    }

    public function test_cannot_publish_revision_belonging_to_another_page(): void
    {
        [$company, $user, $websiteId, $pageId] = $this->fixture();
        $secondPage = (string) Str::uuid();
        DB::table('site_pages')->insert([
            'id' => $secondPage, 'company_id' => $company->id,
            'website_id' => $websiteId, 'slug' => '/about', 'title' => 'About',
        ]);
        $foreignRevision = $this->revision($company, $user, $secondPage, 1);
        $this->expectException(QueryException::class);
        DB::table('site_pages')->where('id', $pageId)->update(['published_revision_id' => $foreignRevision]);
    }

    public function test_revisions_are_immutable_in_postgresql(): void
    {
        [$company, $user, , $pageId] = $this->fixture();
        $revision = $this->revision($company, $user, $pageId, 1);
        $this->expectException(QueryException::class);
        DB::table('site_page_revisions')->where('id', $revision)->update([
            'content' => json_encode(['sections' => []]),
        ]);
    }

    public function test_cross_tenant_revision_is_rejected_by_composite_foreign_key(): void
    {
        [$company, $user, , $pageId] = $this->fixture();
        $other = Company::create(['slug' => 'other', 'name' => 'Another Venue']);
        $this->expectException(QueryException::class);
        DB::table('site_page_revisions')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $other->id,
            'page_id' => $pageId,
            'revision_number' => 1,
            'content' => json_encode(['sections' => []]),
            'created_by' => $user->id,
        ]);
    }

    public function test_audit_history_cannot_be_deleted(): void
    {
        [$company, $user] = $this->fixture();
        $id = (string) Str::uuid();
        DB::table('audit_events')->insert([
            'id' => $id,
            'company_id' => $company->id,
            'actor_id' => $user->id,
            'action' => 'website.updated',
            'entity_type' => 'site_page',
            'entity_id' => 'example',
            'context' => '{}',
        ]);

        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $id)->delete();
    }
}
