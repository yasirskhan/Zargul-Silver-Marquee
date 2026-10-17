<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\TenantAccess;
use Filament\Panel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug): Company
    {
        return Company::create(['name' => $slug, 'slug' => $slug]);
    }

    private function hall(Company $company, string $slug): Hall
    {
        return Hall::create([
            'company_id' => $company->id,
            'name' => $slug,
            'slug' => $slug,
            'capacity' => 500,
            'included_chairs' => 300,
            'included_tables' => 30,
        ]);
    }

    private function user(string $email): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'testing-only-not-a-real-credential']);
        $user->markEmailAsVerified();

        return $user;
    }

    private function membership(User $user, Company $company, string $role, array $rights = []): CompanyMembership
    {
        return CompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => $role,
            ...$rights,
        ]);
    }

    public function test_owner_access_is_scoped_to_own_company_only(): void
    {
        $a = $this->company('zargul');
        $b = $this->company('different-venue');
        $hallA = $this->hall($a, 'grand');
        $hallB = $this->hall($b, 'garden');
        $owner = $this->user('owner@example.test');
        $this->membership($owner, $a, 'owner');
        $access = app(TenantAccess::class);

        $this->assertTrue($access->canViewHall($owner, $hallA));
        $this->assertFalse($access->canViewHall($owner, $hallB));
        $this->assertTrue($access->canPublishWebsite($owner, $a->id));
        $this->assertFalse($access->canPublishWebsite($owner, $b->id));
        $this->assertTrue($owner->canAccessPanel(Panel::make()->id('admin')));
    }

    public function test_manager_only_sees_assigned_hall_and_cannot_publish_website_by_default(): void
    {
        $company = $this->company('zargul');
        $grand = $this->hall($company, 'grand');
        $garden = $this->hall($company, 'garden');
        $manager = $this->user('manager@example.test');
        $membership = $this->membership($manager, $company, 'manager');
        HallAssignment::create(['company_id' => $company->id, 'membership_id' => $membership->id, 'hall_id' => $grand->id]);
        $access = app(TenantAccess::class);

        $this->assertTrue($access->canManageBookings($manager, $grand));
        $this->assertFalse($access->canManageBookings($manager, $garden));
        $this->assertFalse($access->canEditWebsite($manager, $company->id));
        $this->assertFalse($access->canPublishWebsite($manager, $company->id));
    }

    public function test_delegated_site_editor_does_not_inherit_publish_right(): void
    {
        $company = $this->company('zargul');
        $editor = $this->user('editor@example.test');
        $this->membership($editor, $company, 'manager', ['can_edit_site' => true]);
        $access = app(TenantAccess::class);
        $this->assertTrue($access->canEditWebsite($editor, $company->id));
        $this->assertFalse($access->canPublishWebsite($editor, $company->id));
    }

    public function test_unverified_owner_with_active_membership_cannot_access_hall_or_website(): void
    {
        $company = $this->company('zargul');
        $hall = $this->hall($company, 'grand');
        $owner = $this->user('unverified@example.test');
        $this->membership($owner, $company, 'owner');
        $owner->forceFill(['email_verified_at' => null])->save();

        $access = app(TenantAccess::class);

        $this->assertFalse($access->canViewHall($owner, $hall));
        $this->assertFalse($access->canPublishWebsite($owner, $company->id));
        $this->assertFalse($access->canConfigureHall($owner, $hall));
    }

    public function test_cached_verified_user_loses_access_after_persisted_email_verification_reset(): void
    {
        $company = $this->company('zargul');
        $hall = $this->hall($company, 'grand');
        $owner = $this->user('revoked-verification@example.test');
        $this->membership($owner, $company, 'owner');
        $access = app(TenantAccess::class);

        $this->assertTrue($access->canViewHall($owner, $hall));
        DB::table('users')->where('id', $owner->id)->update(['email_verified_at' => null]);

        // The cached User still looks verified, but the persisted authorization must win.
        $this->assertTrue($owner->hasVerifiedEmail());
        $this->assertFalse($access->canViewHall($owner, $hall));
        $this->assertFalse($access->canPublishWebsite($owner, $company->id));
        $this->assertFalse($access->canConfigureHall($owner, $hall));
    }

    public function test_disabled_staff_cannot_enter_panel_or_access_hall(): void
    {
        $company = $this->company('zargul');
        $hall = $this->hall($company, 'grand');
        $user = $this->user('disabled@example.test');
        $this->membership($user, $company, 'owner');
        $user->update(['is_active' => false]);

        $this->assertFalse(app(TenantAccess::class)->canViewHall($user, $hall));
        $this->assertFalse($user->canAccessPanel(Panel::make()->id('admin')));
    }

    public function test_database_rejects_cross_company_hall_assignments(): void
    {
        $a = $this->company('zargul');
        $b = $this->company('other');
        $manager = $this->user('manager@example.test');
        $membership = $this->membership($manager, $a, 'manager');
        $hall = $this->hall($b, 'garden');

        $this->expectException(QueryException::class);
        HallAssignment::create([
            'company_id' => $a->id,
            'membership_id' => $membership->id,
            'hall_id' => $hall->id,
        ]);
    }

    public function test_invalid_role_is_rejected_by_postgresql_constraint(): void
    {
        $company = $this->company('zargul');
        $user = $this->user('stranger@example.test');
        $this->expectException(QueryException::class);
        $this->membership($user, $company, 'superuser-without-policy');
    }
}
