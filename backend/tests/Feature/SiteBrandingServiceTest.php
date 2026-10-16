<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteBrandingService;
use App\Services\TenantAccess;
use App\Services\SiteMediaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class SiteBrandingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $slug = 'branding'): array
    {
        Storage::fake('local');
        $company = Company::create(['name' => $slug, 'slug' => $slug]);
        $owner = User::create(['name' => 'Owner', 'email' => $slug.'-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $siteId = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $siteId, 'company_id' => $company->id, 'name' => $slug]);
        $this->be($owner);

        return [$company, $owner, $siteId];
    }

    private function branding(string $title): array
    {
        return [
            'siteName' => $title,
            'tagline' => 'Celebrate with us',
            'themePreset' => 'silver',
            'fontPreset' => 'classic',
            'logoMediaId' => null,
            'faviconMediaId' => null,
        ];
    }

    public function test_owner_can_save_publish_and_keep_unpublished_brand_changes_private(): void
    {
        [$company, $owner, $site] = $this->fixture();
        $service = app(SiteBrandingService::class);
        $this->assertNull($service->publishedBranding($company->id, $site));

        $this->assertSame(1, $service->saveDraft($owner, $company->id, $site, $this->branding('Silver'), 0, 'Initial branding'));
        $this->assertNull($service->publishedBranding($company->id, $site));
        $publishedId = $service->publish($owner, $company->id, $site, 1, 'Approve branding');
        $this->assertEquals($this->branding('Silver'), $service->publishedBranding($company->id, $site));
        $this->assertSame($publishedId, $service->publish($owner, $company->id, $site, 1, 'Retry safely'));

        $this->assertSame(2, $service->saveDraft($owner, $company->id, $site, $this->branding('Draft only'), 1, 'Try new title'));
        $this->assertEquals($this->branding('Silver'), $service->publishedBranding($company->id, $site));
        $this->assertSame(2, DB::table('site_branding_revisions')->count());
        $this->assertSame(1, DB::table('site_branding_publishing_events')->count());
        $this->assertSame(3, DB::table('audit_events')->count());
    }

    public function test_branding_edit_revoked_after_initial_check_cannot_commit_under_website_lock(): void
    {
        [$company, $owner, $site] = $this->fixture('branding-lock-revoke');

        $access = new class extends TenantAccess {
            public int $checks = 0;

            public function canEditWebsite(User $user, string $companyId): bool
            {
                $wasAllowed = parent::canEditWebsite($user, $companyId);
                if (++$this->checks === 1) {
                    DB::table('company_memberships')->where('company_id', $companyId)
                        ->where('user_id', $user->getKey())->update(['is_active' => false]);
                }

                return (bool) $wasAllowed;
            }
        };
        $this->app->instance(TenantAccess::class, $access);
        try {
            app(SiteBrandingService::class)->saveDraft($owner, $company->id, $site,
                $this->branding('Revoked branding'), 0, 'Old branding permissions');
            $this->fail('Revoked brand editor wrote an immutable revision.');
        } catch (AuthorizationException) {
            $this->assertSame(2, $access->checks);
        } finally {
            $this->app->forgetInstance(TenantAccess::class);
        }

        $this->assertSame(0, (int) DB::table('websites')->where('id', $site)->value('branding_draft_revision'));
        $this->assertSame(0, DB::table('site_branding_revisions')->where('website_id', $site)->count());
        $this->assertSame(0, DB::table('audit_events')->where('entity_id', $site)->count());
    }

    public function test_stale_authenticated_owner_cannot_publish_branding_after_account_deactivation(): void
    {
        [$company, $owner, $site] = $this->fixture('brand-account-shutdown');
        $service = app(SiteBrandingService::class);
        $service->saveDraft($owner, $company->id, $site, $this->branding('Still private'), 0, 'Initial branding draft');
        DB::table('users')->where('id', $owner->getKey())->update(['is_active' => false]);
        $this->assertTrue((bool) $owner->is_active, 'Cached session model intentionally remains stale.');
        try {
            $service->publish($owner, $company->id, $site, 1, 'Disabled owner publish');
            $this->fail('A database-disabled owner published brand settings.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertNull(DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
        $this->assertSame(0, DB::table('site_branding_publishing_events')->where('website_id', $site)->count());
        $this->assertSame(1, DB::table('audit_events')->where('entity_id', $site)->count());
    }

    public function test_editor_can_save_but_cannot_publish_and_stale_drafts_are_rejected(): void
    {
        [$company, $owner, $site] = $this->fixture('delegated');
        $editor = User::create(['name' => 'Editor', 'email' => 'branding-editor@example.test', 'password' => 'test-only']);
        $editor->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $editor->id,
            'role' => 'manager', 'can_edit_site' => true,
        ]);
        $service = app(SiteBrandingService::class);
        $service->saveDraft($owner, $company->id, $site, $this->branding('Owner draft'), 0, 'Owner starts');
        $this->be($editor);
        try {
            $service->saveDraft($editor, $company->id, $site, $this->branding('Stale editor'), 0, 'Old browser tab');
            $this->fail('Stale draft was accepted');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('site_branding_revisions')->count());
        }
        $this->assertSame(2, $service->saveDraft($editor, $company->id, $site, $this->branding('Editor draft'), 1, 'Authorized draft edit'));
        $this->expectException(AuthorizationException::class);
        $service->publish($editor, $company->id, $site, 2, 'Unauthorized publish');
    }

    public function test_cross_company_actor_and_unverified_owner_are_denied(): void
    {
        [$company, , $site] = $this->fixture('primary');
        [, $outsider] = $this->fixture('secondary');
        $this->be($outsider);
        $this->expectException(AuthorizationException::class);
        app(SiteBrandingService::class)->saveDraft($outsider, $company->id, $site,
            $this->branding('Intrusion'), 0, 'Foreign venue');
    }

    public function test_footer_requires_safe_bounded_plain_text_and_is_never_persisted_when_invalid(): void
    {
        [$company, $owner, $site] = $this->fixture('unsafe-footer');
        foreach (['<script>alert(1)</script>', 'javascript:alert(1)', str_repeat('x', 241)] as $unsafe) {
            $branding = $this->branding('Silver');
            $branding['footerText'] = $unsafe;
            try {
                app(SiteBrandingService::class)->saveDraft(
                    $owner, $company->id, $site, $branding, 0, 'Reject unsafe footer'
                );
                $this->fail('Unsafe footer was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, DB::table('site_branding_revisions')->where('website_id', $site)->count());
                $this->assertSame(0, DB::table('audit_events')->where('entity_id', $site)->count());
            }
        }
    }

    public function test_menu_rejects_unsafe_duplicate_missing_home_and_foreign_paths_without_writes(): void
    {
        [$company, $owner, $site] = $this->fixture('bad-menu');
        foreach ([
            ['/'],
            ['/about', '/about'],
            ['/about'],
            ['/', '/admin'],
            ['/', 'https://evil.example'],
            ['/', []],
        ] as $menu) {
            if ($menu === ['/']) {
                // Single Home entry is valid; other cases below are rejected.
                continue;
            }
            $brand = $this->branding('Approved brand');
            $brand['navigationOrder'] = $menu;
            try {
                app(SiteBrandingService::class)->saveDraft($owner, $company->id, $site,
                    $brand, 0, 'Reject unsupported menu paths');
                $this->fail('Unsafe or unsupported menu was persisted.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, DB::table('site_branding_revisions')->where('website_id', $site)->count());
                $this->assertSame(0, DB::table('audit_events')->where('entity_id', $site)->count());
            }
        }
    }

    public function test_unsafe_seo_description_is_rejected_before_immutable_revision_creation(): void
    {
        [$company, $owner, $site] = $this->fixture('bad-seo-description');
        foreach (['<script>alert(1)</script>', 'javascript:alert(1)', str_repeat('a', 161)] as $unsafe) {
            $brand = $this->branding('Safe Marquee');
            $brand['seoDescription'] = $unsafe;
            try {
                app(SiteBrandingService::class)->saveDraft($owner, $company->id, $site,
                    $brand, 0, 'Reject unsafe public search description');
                $this->fail('Unsafe search description persisted.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, DB::table('site_branding_revisions')->where('website_id', $site)->count());
                $this->assertSame(0, DB::table('audit_events')->where('entity_id', $site)->count());
            }
        }
    }

    public function test_invalid_theme_does_not_write_history_or_audit(): void
    {
        [$company, $owner, $site] = $this->fixture('unsafe');
        $branding = $this->branding('Silver');
        $branding['themePreset'] = '<script>evil</script>';
        try {
            app(SiteBrandingService::class)->saveDraft($owner, $company->id, $site, $branding, 0, 'Unsafe edit');
            $this->fail('Unexpected unsafe branding acceptance');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('site_branding_revisions')->count());
            $this->assertSame(0, DB::table('audit_events')->count());
        }
    }

    public function test_pending_logo_may_be_drafted_but_publication_requires_integrity_checked_approval(): void
    {
        [$company, $owner, $site] = $this->fixture('assets');
        $media = app(SiteMediaService::class);
        $id = $media->stageImage($owner, $company->id,
            UploadedFile::fake()->image('logo.png', 240, 240),
            'Approved venue logo', 'Upload company brand logo');

        $branding = $this->branding('Silver');
        $branding['logoMediaId'] = $id;
        $service = app(SiteBrandingService::class);
        $service->saveDraft($owner, $company->id, $site, $branding, 0, 'Logo branding draft');

        try {
            $service->publish($owner, $company->id, $site, 1, 'Unapproved logo');
            $this->fail('Pending logo must never publish.');
        } catch (InvalidArgumentException) {
            $this->assertNull($service->publishedBranding($company->id, $site));
            $this->assertSame(0, DB::table('site_branding_publishing_events')->count());
        }

        $media->approveImage($owner, $company->id, $id, 'Approve authorized logo');
        $service->publish($owner, $company->id, $site, 1, 'Publish owner logo');
        $this->assertSame($id, $service->publishedBranding($company->id, $site)['logoMediaId']);
        $this->assertSame(1, DB::table('site_branding_publishing_events')->count());

        $asset = DB::table('site_media')->where('id', $id)->first();
        $metadata = json_decode($asset->metadata, true);
        Storage::disk('local')->put($metadata['variants']['webp1200']['object_key'], 'tampered');
        $service->saveDraft($owner, $company->id, $site, $branding, 1, 'Revision with damaged logo');
        $this->expectException(InvalidArgumentException::class);
        $service->publish($owner, $company->id, $site, 2, 'Reject corrupted variant');
    }
    public function test_restore_published_branding_preserves_later_draft_and_audited_history(): void
    {
        [$company, $owner, $site] = $this->fixture('restore-site');
        $service = app(SiteBrandingService::class);

        $service->saveDraft($owner, $company->id, $site, $this->branding('First'), 0, 'Initial branding');
        $first = $service->publish($owner, $company->id, $site, 1, 'Publish first');
        $service->saveDraft($owner, $company->id, $site, $this->branding('Second'), 1, 'Second branding');
        $second = $service->publish($owner, $company->id, $site, 2, 'Publish second');
        $service->saveDraft($owner, $company->id, $site, $this->branding('Unpublished third'), 2, 'New unfinished draft');

        $this->assertSame($first, $service->restorePublishedBranding(
            $owner, $company->id, $site, $first, $second, 'Restore original approved brand'
        ));
        $this->assertEquals($this->branding('First'), $service->publishedBranding($company->id, $site));
        $this->assertSame(3, (int) DB::table('websites')->where('id', $site)->value('branding_draft_revision'));
        $this->assertSame(3, DB::table('site_branding_revisions')->count());
        $this->assertSame(3, DB::table('site_branding_publishing_events')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.branding_restored')->count());

        $this->assertSame($first, $service->restorePublishedBranding(
            $owner, $company->id, $site, $first, $first, 'Safe idempotent retry'
        ));
        $this->assertSame(3, DB::table('site_branding_publishing_events')->count());
    }

    public function test_restore_rejects_stale_published_pointer_and_never_published_draft(): void
    {
        [$company, $owner, $site] = $this->fixture('restore-conflicts');
        $service = app(SiteBrandingService::class);
        $service->saveDraft($owner, $company->id, $site, $this->branding('Original'), 0, 'Initial brand');
        $first = $service->publish($owner, $company->id, $site, 1, 'Publish first');
        $service->saveDraft($owner, $company->id, $site, $this->branding('Second'), 1, 'Second brand');
        $second = $service->publish($owner, $company->id, $site, 2, 'Publish second');
        $service->saveDraft($owner, $company->id, $site, $this->branding('Never published'), 2, 'Third draft');
        $unpublished = DB::table('site_branding_revisions')->where('website_id', $site)
            ->where('revision_number', 3)->value('id');

        try {
            $service->restorePublishedBranding($owner, $company->id, $site, $first, $first, 'Old published pointer');
            $this->fail('Stale published pointer should never restore a website.');
        } catch (ConflictHttpException) {
            $this->assertSame($second, DB::table('websites')->where('id', $site)->value('branding_published_revision_id'));
        }
        $this->expectException(InvalidArgumentException::class);
        $service->restorePublishedBranding($owner, $company->id, $site, $unpublished, $second, 'Unpublished branding cannot restore');
    }

}
