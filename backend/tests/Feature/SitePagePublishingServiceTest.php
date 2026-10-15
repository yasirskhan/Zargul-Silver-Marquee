<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SitePagePublishingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class SitePagePublishingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function setupWebsite(string $slug = 'venue'): array
    {
        $company = Company::create(['name' => $slug, 'slug' => $slug]);
        $owner = User::create([
            'name' => 'Owner', 'email' => $slug.'-owner@example.test', 'password' => 'test-only-not-production',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $websiteId = (string) Str::uuid();
        $pageId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $websiteId, 'company_id' => $company->id, 'name' => $slug,
        ]);
        DB::table('site_pages')->insert([
            'id' => $pageId, 'company_id' => $company->id,
            'website_id' => $websiteId, 'slug' => '/', 'title' => 'Home',
        ]);

        return [$company, $owner, $pageId];
    }

    private function content(string $title): array
    {
        return ['sections' => [['id' => 'welcome', 'type' => 'text', 'data' => ['title' => $title]]]];
    }

    private function service(): SitePagePublishingService
    {
        return app(SitePagePublishingService::class);
    }

    public function test_owner_can_save_and_publish_with_atomic_audit_and_immutable_history(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite();
        $this->be($owner);

        $this->assertSame(1, $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('First'), 0, 'Initial content'));
        $this->assertNull($this->service()->publishedContent($company->id, $pageId));
        $revisionId = $this->service()->publish($owner, $company->id, $pageId, 1, 'Owner approved');
        $this->assertEquals($this->content('First'), $this->service()->publishedContent($company->id, $pageId));
        $this->assertSame($revisionId, $this->service()->publish($owner, $company->id, $pageId, 1, 'Safe retry'));
        $this->assertSame(1, DB::table('site_publishing_events')->count());
        $this->assertSame(2, DB::table('audit_events')->count());

        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('Not yet published'), 1, 'Change draft');
        $this->assertEquals($this->content('First'), $this->service()->publishedContent($company->id, $pageId));
        $this->assertSame(2, DB::table('site_page_revisions')->count());
        $this->assertSame(3, DB::table('audit_events')->count());
    }

    public function test_two_editors_cannot_silently_overwrite_a_stale_draft(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite();
        $second = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'test-only-not-production',
        ]);
        $second->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $second->id,
            'role' => 'manager', 'can_edit_site' => true,
        ]);
        $this->be($owner);
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('Owner edit'), 0, 'Edit');
        $this->be($second);
        $this->expectException(ConflictHttpException::class);
        $this->service()->saveDraft($second, $company->id, $pageId, $this->content('Stale editor'), 0, 'Old tab');
    }

    public function test_delegated_editor_cannot_publish_without_permission(): void
    {
        [$company, , $pageId] = $this->setupWebsite();
        $editor = User::create([
            'name' => 'Editor', 'email' => 'only-editor@example.test', 'password' => 'test-only-not-production',
        ]);
        $editor->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $editor->id,
            'role' => 'manager', 'can_edit_site' => true,
        ]);
        $this->be($editor);
        $this->service()->saveDraft($editor, $company->id, $pageId, $this->content('Editor draft'), 0, 'Prepare');
        $this->expectException(AuthorizationException::class);
        $this->service()->publish($editor, $company->id, $pageId, 1, 'Not delegated');
    }

    public function test_cross_company_edit_and_unverified_actor_are_denied(): void
    {
        [$company, , $pageId] = $this->setupWebsite('primary');
        [, $outsider] = $this->setupWebsite('secondary');
        $this->be($outsider);
        $this->expectException(AuthorizationException::class);
        $this->service()->saveDraft($outsider, $company->id, $pageId, $this->content('Intrusion'), 0, 'Not allowed');
    }

    public function test_invalid_layout_rolls_back_without_revision_or_audit(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite();
        $this->be($owner);
        $bad = $this->content('<script>evil</script>');
        try {
            $this->service()->saveDraft($owner, $company->id, $pageId, $bad, 0, 'Rejected');
            $this->fail('Unsafe layout was accepted');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('site_page_revisions')->count());
            $this->assertSame(0, DB::table('audit_events')->count());
            $this->assertSame(0, DB::table('site_pages')->where('id', $pageId)->value('draft_revision'));
        }
    }

    public function test_publishing_requires_latest_revision_and_approved_company_media(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite();
        $this->be($owner);
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('First'), 0, 'Start');
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('Second'), 1, 'Rewrite');
        try {
            $this->service()->publish($owner, $company->id, $pageId, 1, 'Stale publish');
            $this->fail('Stale publish accepted');
        } catch (ConflictHttpException) {
            $this->assertNull($this->service()->publishedContent($company->id, $pageId));
            $this->assertSame(0, DB::table('site_publishing_events')->count());
        }

        $media = $this->content('Image');
        $media['sections'][] = [
            'id' => 'photo', 'type' => 'image',
            'data' => ['imageId' => (string) Str::uuid(), 'alt' => 'Hall'],
        ];
        try {
            $this->service()->saveDraft($owner, $company->id, $pageId, $media, 2, 'Unknown media');
            $this->fail('Foreign or nonexistent media accepted');
        } catch (InvalidArgumentException) {
            $this->assertSame(2, DB::table('site_page_revisions')->count());
            $this->assertSame(2, DB::table('audit_events')->count());
        }
    }

    public function test_failed_audit_insert_rolls_back_revision_and_publish_changes(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite('rollback-venue');
        $this->be($owner);
        $this->rejectAuditWrites();
        try {
            $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('First'), 0, 'Initial');
            $this->fail('An audit failure must abort the draft transaction');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('Injected audit rejection', $exception->getMessage());
        } finally {
            $this->allowAuditWrites();
        }
        $this->assertSame(0, DB::table('site_page_revisions')->count());
        $this->assertSame(0, DB::table('site_pages')->where('id', $pageId)->value('draft_revision'));

        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('First'), 0, 'Initial');
        $this->rejectAuditWrites();
        try {
            $this->service()->publish($owner, $company->id, $pageId, 1, 'Approve');
            $this->fail('An audit failure must abort the publish transaction');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('Injected audit rejection', $exception->getMessage());
        } finally {
            $this->allowAuditWrites();
        }
        $this->assertNull($this->service()->publishedContent($company->id, $pageId));
        $this->assertSame(0, DB::table('site_publishing_events')->count());
        $this->assertSame(1, DB::table('audit_events')->count());
    }

    private function rejectAuditWrites(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_test_audit_insert() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Injected audit rejection';
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_test_audit_insert_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_test_audit_insert()');
    }

    private function allowAuditWrites(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fail_test_audit_insert_trigger ON audit_events');
        DB::statement('DROP FUNCTION IF EXISTS fail_test_audit_insert()');
    }

    public function test_unverified_owner_cannot_write_content(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite('unverified');
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->be($owner);
        $this->expectException(AuthorizationException::class);
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('Unverified'), 0, 'Not allowed');
    }
}
