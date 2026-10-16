<?php

namespace Tests\Feature;

use App\Filament\Pages\WebsiteWorkspace;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteWorkspaceService;
use App\Services\TenantAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteWorkspaceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function site(Company $company, string $title): string
    {
        $websiteId = (string) Str::uuid();
        $pageId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $websiteId, 'company_id' => $company->id, 'name' => $title,
        ]);
        DB::table('site_pages')->insert([
            'id' => $pageId, 'company_id' => $company->id, 'website_id' => $websiteId,
            'slug' => '/', 'title' => $title,
        ]);

        return $pageId;
    }

    public function test_verified_owner_can_only_list_own_active_company_pages(): void
    {
        $a = Company::create(['name' => 'Silver', 'slug' => 'silver']);
        $b = Company::create(['name' => 'Foreign', 'slug' => 'foreign']);
        $owner = User::create(['name' => 'Owner', 'email' => 'workspace@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $a->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $ownPage = $this->site($a, 'Home');
        $foreignPage = $this->site($b, 'Foreign home');
        $workspace = app(SiteWorkspaceService::class);

        $this->assertSame([$a->id => 'Silver'], $workspace->companyOptions($owner));
        $this->assertSame([$ownPage => 'Home'], $workspace->pageOptions($owner, $a->id));
        $this->assertSame([], $workspace->pageOptions($owner, $b->id));
        $this->assertNull($workspace->page($owner, $b->id, $foreignPage));
        $this->assertNotNull($workspace->page($owner, $a->id, $ownPage));

        $a->update(['is_active' => false]);
        $this->assertSame([], $workspace->companyOptions($owner));
        $this->assertNull($workspace->page($owner, $a->id, $ownPage));
    }

    public function test_reception_unverified_editor_and_revoked_membership_cannot_open_workspace(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'silver']);
        $page = $this->site($company, 'Home');
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'test-only']);
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $staff->id, 'role' => 'reception',
        ]);
        $workspace = app(SiteWorkspaceService::class);
        $this->assertSame([], $workspace->companyOptions($staff));
        $staff->markEmailAsVerified();
        $this->assertNull($workspace->page($staff, $company->id, $page));

        $membership->update(['can_edit_site' => true]);
        $this->assertSame([$company->id => 'Silver'], $workspace->companyOptions($staff));
        $membership->update(['is_active' => false]);
        $this->assertSame([], $workspace->companyOptions($staff));
    }

    public function test_stale_owner_database_account_shutdown_removes_workspace_and_create_authority(): void
    {
        $company = Company::create(['name' => 'Private Marquee', 'slug' => 'private-marquee']);
        $owner = User::create(['name' => 'Owner', 'email' => 'stale-creator@example.test', 'password' => 'testing-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $page = $this->site($company, 'Private home');
        $websiteId = DB::table('site_pages')->where('id', $page)->value('website_id');
        $this->be($owner);
        $workspace = app(SiteWorkspaceService::class);
        $this->assertArrayHasKey($company->id, $workspace->companyOptions($owner));

        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active, 'Hydrated owner is intentionally stale.');
        $this->assertSame([], $workspace->companyOptions($owner));
        try {
            $workspace->createPage($owner, $company->id, $websiteId, (string) Str::uuid(),
                '/about', 'A private page', 'Must reject disabled editor');
            $this->fail('Disabled account created a new unpublished site page.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('site_pages')->where('website_id', $websiteId)->count());
            $this->assertSame(0, DB::table('audit_events')->where('action', 'website.page_created')->count());
        }
    }

    public function test_account_revoked_after_first_edit_check_cannot_create_page_after_website_lock(): void
    {
        $company = Company::create(['name' => 'Lock Marquee', 'slug' => 'lock-marquee']);
        $owner = User::create(['name' => 'Owner', 'email' => 'lock-creator@example.test', 'password' => 'testing-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $page = $this->site($company, 'Lock home');
        $websiteId = DB::table('site_pages')->where('id', $page)->value('website_id');
        $this->be($owner);

        $access = new class extends TenantAccess {
            public int $checks = 0;

            public function canEditWebsite(User $user, string $companyId): bool
            {
                $allowed = parent::canEditWebsite($user, $companyId);
                if (++$this->checks === 1) {
                    DB::table('users')->where('id', $user->getKey())
                        ->update(['is_active' => false]);
                }
                return (bool) $allowed;
            }
        };
        $this->app->instance(TenantAccess::class, $access);
        try {
            app(SiteWorkspaceService::class)->createPage($owner, $company->id, $websiteId,
                (string) Str::uuid(), '/halls', 'Unpublished new halls', 'Permission revoked during write lock');
            $this->fail('New page created using only stale pre-lock account authorization.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $access->checks);
        } finally {
            $this->app->forgetInstance(TenantAccess::class);
        }
        $this->assertSame(1, DB::table('site_pages')->where('website_id', $websiteId)->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'website.page_created')->count());
    }

    public function test_filament_workspace_is_registered_only_under_admin_panel(): void
    {
        $panel = (new \App\Providers\Filament\AdminPanelProvider(app()))->panel(Panel::make());
        $this->assertContains(WebsiteWorkspace::class, $panel->getPages());
        $this->assertTrue($panel->hasMultiFactorAuthentication());
    }
}
