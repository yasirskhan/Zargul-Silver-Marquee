<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\SitePage;
use App\Models\User;
use App\Policies\SitePagePolicy;
use App\Services\TenantAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase 1 company/hall isolation matrix across the CMS and hall policy surfaces. */
class Phase1TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_manager_and_operations_cannot_cross_company_or_unassigned_hall_boundaries(): void
    {
        $companyA = Company::create(['name' => 'Company A', 'slug' => 'phase1-company-a']);
        $companyB = Company::create(['name' => 'Company B', 'slug' => 'phase1-company-b']);

        $ownerA = $this->user('owner-a@example.test');
        $managerA = $this->user('manager-a@example.test');
        $operationsA = $this->user('operations-a@example.test');
        $ownerB = $this->user('owner-b@example.test');

        $this->membership($companyA, $ownerA, 'owner');
        $managerMembership = $this->membership($companyA, $managerA, 'manager', canEditSite: true);
        $operationsMembership = $this->membership($companyA, $operationsA, 'operations');
        $this->membership($companyB, $ownerB, 'owner');

        $hallA = Hall::create([
            'company_id' => $companyA->id, 'name' => 'Hall A', 'slug' => 'phase1-hall-a', 'capacity' => 100,
        ]);
        $hallB = Hall::create([
            'company_id' => $companyB->id, 'name' => 'Hall B', 'slug' => 'phase1-hall-b', 'capacity' => 100,
        ]);
        HallAssignment::create([
            'company_id' => $companyA->id, 'hall_id' => $hallA->id,
            'membership_id' => $operationsMembership->id,
        ]);
        // Deliberately assign the A manager nowhere; manager hall access stays assignment-scoped.
        $this->assertNotNull($managerMembership);

        [$websiteA, $pageA] = $this->page($companyA, 'phase1-a');
        [$websiteB, $pageB] = $this->page($companyB, 'phase1-b');
        $pageAModel = SitePage::query()->whereKey($pageA)->firstOrFail();
        $pageBModel = SitePage::query()->whereKey($pageB)->firstOrFail();

        $access = app(TenantAccess::class);
        $this->assertTrue($access->canEditWebsite($ownerA, $companyA->id));
        $this->assertFalse($access->canEditWebsite($ownerA, $companyB->id));
        $this->assertTrue($access->canEditWebsite($managerA, $companyA->id));
        $this->assertFalse($access->canEditWebsite($managerA, $companyB->id));
        $this->assertFalse($access->canEditWebsite($ownerB, $companyA->id));

        $this->assertTrue($access->canPublishWebsite($ownerA, $companyA->id));
        $this->assertFalse($access->canPublishWebsite($managerA, $companyA->id));
        $this->assertFalse($access->canPublishWebsite($managerA, $companyB->id));

        $this->assertTrue($access->canConfigureHall($ownerA, $hallA));
        $this->assertFalse($access->canConfigureHall($ownerA, $hallB));
        $this->assertFalse($access->canConfigureHall($managerA, $hallA));
        $this->assertTrue($access->canViewHall($operationsA, $hallA));
        $this->assertFalse($access->canViewHall($operationsA, $hallB));
        $this->assertFalse($access->canViewHall($managerA, $hallA));

        $policy = app(SitePagePolicy::class);
        $this->assertTrue(Gate::forUser($ownerA)->allows('update', $pageAModel));
        $this->assertFalse(Gate::forUser($ownerA)->allows('update', $pageBModel));
        $this->assertTrue(Gate::forUser($managerA)->allows('update', $pageAModel));
        $this->assertFalse(Gate::forUser($managerA)->allows('update', $pageBModel));
        $this->assertTrue(Gate::forUser($ownerA)->allows('publish', $pageAModel));
        $this->assertFalse(Gate::forUser($managerA)->allows('publish', $pageAModel));
        $this->assertFalse(Gate::forUser($ownerB)->allows('publish', $pageAModel));

        // Company B still has its own independent editable website/page.
        $this->assertTrue($access->canEditWebsite($ownerB, $companyB->id));
        $this->assertTrue(Gate::forUser($ownerB)->allows('update', $pageBModel));
        $this->assertNotNull($websiteA);
        $this->assertNotNull($websiteB);
    }

    private function user(string $email): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'test-only']);
        $user->markEmailAsVerified();

        return $user;
    }

    private function membership(
        Company $company,
        User $user,
        string $role,
        bool $canEditSite = false,
    ): CompanyMembership {
        return CompanyMembership::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'can_edit_site' => $canEditSite,
        ]);
    }

    private function page(Company $company, string $suffix): array
    {
        $website = (string) Str::uuid();
        $page = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $website, 'company_id' => $company->id, 'name' => 'Site '.$suffix,
        ]);
        DB::table('site_pages')->insert([
            'id' => $page, 'company_id' => $company->id, 'website_id' => $website,
            'slug' => '/', 'title' => 'Home',
        ]);

        return [$website, $page];
    }
}
