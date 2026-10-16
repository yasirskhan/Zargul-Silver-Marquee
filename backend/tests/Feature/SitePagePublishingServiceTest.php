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

    public function test_revoked_publisher_cannot_publish_or_restore_previous_site_content(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite('revoked-publisher');
        $this->be($owner);
        $service = $this->service();
        $service->saveDraft($owner, $company->id, $pageId, $this->content('Approved initial'), 0, 'Initial draft');
        $first = $service->publish($owner, $company->id, $pageId, 1, 'First publish');
        $service->saveDraft($owner, $company->id, $pageId, $this->content('Second approved'), 1, 'Next draft');
        $second = $service->publish($owner, $company->id, $pageId, 2, 'Second publish');
        $membership = CompanyMembership::query()->where('company_id', $company->id)
            ->where('user_id', $owner->id)->firstOrFail();
        $membership->update(['is_active' => false]);
        try {
            $service->restorePublishedRevision($owner, $company->id, $pageId,
                $first, $second, 'Unauthorized restore after revocation');
            $this->fail('Revoked publisher restored a public page.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        try {
            $service->publish($owner, $company->id, $pageId, 2,
                'Unauthorized publish retry after revocation');
            $this->fail('Revoked publisher retried publication.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame($second, DB::table('site_pages')->where('id', $pageId)->value('published_revision_id'));
        $this->assertSame(2, DB::table('site_publishing_events')->count());
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


    public function test_restore_published_version_records_new_event_and_preserves_newer_draft(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite('restore-page');
        $this->be($owner);
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('Original'), 0, 'First draft');
        $first = $this->service()->publish($owner, $company->id, $pageId, 1, 'First publish');
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('Replacement'), 1, 'Update');
        $second = $this->service()->publish($owner, $company->id, $pageId, 2, 'Second publish');

        $restored = $this->service()->restorePublishedRevision(
            $owner, $company->id, $pageId, $first, $second, 'Restore approved headline'
        );
        $this->assertSame($first, $restored);
        $this->assertEquals($this->content('Original'), $this->service()->publishedContent($company->id, $pageId));
        $this->assertSame(2, DB::table('site_pages')->where('id', $pageId)->value('draft_revision'));
        $this->assertSame(2, DB::table('site_page_revisions')->count());
        $this->assertSame(3, DB::table('site_publishing_events')->count());
        $this->assertSame(1, DB::table('site_publishing_events')->where('action', 'restore')->count());
        $this->assertSame(5, DB::table('audit_events')->count());

        $this->assertSame($first, $this->service()->restorePublishedRevision(
            $owner, $company->id, $pageId, $first, $first, 'Idempotent retry'
        ));
        $this->assertSame(3, DB::table('site_publishing_events')->count());
    }

    public function test_restore_rejects_stale_pointer_and_draft_never_published(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite('restore-safety');
        $this->be($owner);
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('First'), 0, 'First');
        $first = $this->service()->publish($owner, $company->id, $pageId, 1, 'Publish');
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('Unpublished'), 1, 'Second');
        $unpublished = DB::table('site_page_revisions')->where('page_id', $pageId)
            ->where('revision_number', 2)->value('id');
        try {
            $this->service()->restorePublishedRevision(
                $owner, $company->id, $pageId, $first, (string) Str::uuid(), 'Stale pointer'
            );
            $this->fail('Stale expected pointer was accepted');
        } catch (ConflictHttpException) {
            $this->assertSame($first, DB::table('site_pages')->where('id', $pageId)->value('published_revision_id'));
        }
        $this->expectException(InvalidArgumentException::class);
        $this->service()->restorePublishedRevision(
            $owner, $company->id, $pageId, $unpublished, $first, 'Never published'
        );
    }

    public function test_restore_audit_failure_rolls_back_published_pointer_and_event(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite('restore-rollback');
        $this->be($owner);
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('First'), 0, 'First');
        $first = $this->service()->publish($owner, $company->id, $pageId, 1, 'Approved');
        $this->service()->saveDraft($owner, $company->id, $pageId, $this->content('Second'), 1, 'Second');
        $second = $this->service()->publish($owner, $company->id, $pageId, 2, 'Approved');
        $this->rejectAuditWrites();
        try {
            $this->service()->restorePublishedRevision(
                $owner, $company->id, $pageId, $first, $second, 'Rollback failure'
            );
            $this->fail('Restore should fail if audit write fails');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        } finally {
            $this->allowAuditWrites();
        }
        $this->assertSame($second, DB::table('site_pages')->where('id', $pageId)->value('published_revision_id'));
        $this->assertSame(2, DB::table('site_publishing_events')->count());
    }

    public function test_unpublish_is_atomic_exact_retry_and_restores_only_approved_history(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite('unpublish');
        $this->be($owner);
        $service = $this->service();
        $service->saveDraft($owner, $company->id, $pageId, $this->content('First public'), 0, 'Draft');
        $first = $service->publish($owner, $company->id, $pageId, 1, 'Approve public');
        $service->saveDraft($owner, $company->id, $pageId, $this->content('Private newer'), 1, 'Future draft');
        $requestId = (string) Str::uuid();
        $service->unpublish($owner, $company->id, $pageId, $requestId,
            $first, 'Temporarily take published page offline');
        $this->assertNull($service->publishedContent($company->id, $pageId));
        $this->assertSame(2, (int) DB::table('site_pages')->where('id', $pageId)->value('draft_revision'));
        $this->assertSame(1, DB::table('site_publishing_events')->where('action', 'unpublish')->count());
        $service->unpublish($owner, $company->id, $pageId, $requestId,
            $first, 'Temporarily take published page offline');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.unpublished')->count());
        $privateRevision = DB::table('site_page_revisions')->where('page_id', $pageId)
            ->where('revision_number', 2)->value('id');
        try {
            $service->restorePublishedRevision($owner, $company->id, $pageId,
                $privateRevision, null, 'Unsafe unseen draft restore');
            $this->fail('Never-published draft was restored while page offline.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        try {
            $service->unpublish($owner, $company->id, $pageId, $requestId,
                $first, 'Conflicting replay reason');
            $this->fail('Changed unpublish replay was accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->assertSame($first, $service->restorePublishedRevision(
            $owner, $company->id, $pageId, $first, null, 'Restore approved public content'
        ));
        $this->assertEquals($this->content('First public'), $service->publishedContent($company->id, $pageId));
        $this->assertSame(2, (int) DB::table('site_pages')->where('id', $pageId)->value('draft_revision'));
        $this->expectException(ConflictHttpException::class);
        $service->unpublish($owner, $company->id, $pageId, $requestId,
            $first, 'Temporarily take published page offline');
    }

    public function test_unpublish_audit_rejection_rolls_back_pointer_and_event(): void
    {
        [$company, $owner, $pageId] = $this->setupWebsite('unpublish-audit');
        $this->be($owner);
        $service = $this->service();
        $service->saveDraft($owner, $company->id, $pageId, $this->content('Public'), 0, 'Draft');
        $first = $service->publish($owner, $company->id, $pageId, 1, 'Approve');
        $this->rejectAuditWrites();
        try {
            $service->unpublish($owner, $company->id, $pageId, (string) Str::uuid(),
                $first, 'Audit must be atomic');
            $this->fail('Unpublish succeeded with failed audit.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        } finally {
            $this->allowAuditWrites();
        }
        $this->assertSame($first, DB::table('site_pages')->where('id', $pageId)->value('published_revision_id'));
        $this->assertSame(1, DB::table('site_publishing_events')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'website.unpublished')->count());
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
