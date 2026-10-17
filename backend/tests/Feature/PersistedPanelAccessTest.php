<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersistedPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cached_active_owner_cannot_enter_panel_after_database_suspension(): void
    {
        $company = Company::create(['name' => 'Synthetic Marquee', 'slug' => 'panel-suspension']);
        $owner = User::create(['name' => 'Synthetic Owner',
            'email' => 'panel-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $panel = Panel::make()->id('admin');

        $this->assertTrue($owner->canAccessPanel($panel));
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active, 'Authenticated model remains stale.');
        $this->assertFalse($owner->canAccessPanel($panel));
        $this->assertFalse($owner->canAccessPanel(Panel::make()->id('foreign')));
    }

    public function test_panel_still_requires_current_active_company_membership(): void
    {
        $company = Company::create(['name' => 'Synthetic Marquee', 'slug' => 'panel-membership']);
        $owner = User::create(['name' => 'Synthetic Owner',
            'email' => 'panel-member@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        $membership = CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $panel = Panel::make()->id('admin');

        $this->assertTrue($owner->canAccessPanel($panel));
        DB::table('company_memberships')->where('id', $membership->id)->update(['is_active' => false]);
        $this->assertFalse($owner->canAccessPanel($panel));
        DB::table('company_memberships')->where('id', $membership->id)->update(['is_active' => true]);
        $this->assertTrue($owner->canAccessPanel($panel));
        DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
        $this->assertFalse($owner->canAccessPanel($panel));
    }
}
