<?php

namespace Tests\Feature;

use App\Filament\Pages\WebsiteWorkspace;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SiteWorkspaceService;
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

    public function test_filament_workspace_is_registered_only_under_admin_panel(): void
    {
        $panel = app(\App\Providers\Filament\AdminPanelProvider::class)->panel(Panel::make());
        $this->assertContains(WebsiteWorkspace::class, $panel->getPages());
        $this->assertTrue($panel->hasMultiFactorAuthentication());
    }
}
