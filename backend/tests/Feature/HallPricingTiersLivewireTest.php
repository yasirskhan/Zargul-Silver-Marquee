<?php

namespace Tests\Feature;

use App\Filament\Pages\HallPricingTiers;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HallPricingTiersLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_publishes_exact_guest_tiers_from_the_page(): void
    {
        $company = Company::create(['name' => 'Tier UI', 'slug' => 'tier-ui']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Hall',
            'slug' => 'tier-ui-hall', 'capacity' => 500]);
        $owner = User::create(['name' => 'Owner', 'email' => 'tier-ui@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        Livewire::test(HallPricingTiers::class)
            ->assertSet('tiers', [])
            ->call('addTier')
            ->set('tiers', [
                ['minGuests' => 1, 'maxGuests' => 100, 'priceMinor' => 150000],
                ['minGuests' => 101, 'maxGuests' => 300, 'priceMinor' => 300000],
            ])
            ->set('currencyCode', 'PKR')
            ->set('reason', 'Owner approved exact guest tiers')
            ->call('save')->assertHasNoErrors()
            ->assertSet('expectedRevision', 1);

        $this->assertSame(1, DB::table('hall_guest_pricing_revisions')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'hall.guest_pricing_updated')->count());
        $this->assertSame(300000, app(\App\Services\HallGuestPricingService::class)
            ->quote($company->id, $hall->id, 250)['priceMinor']);
    }
    public function test_manager_and_forged_cross_company_selection_are_denied(): void
    {
        $company = Company::create(['name' => 'Tier UI', 'slug' => 'tier-ui-2']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Hall',
            'slug' => 'tier-ui-hall-2', 'capacity' => 500]);
        $owner = User::create(['name' => 'Owner', 'email' => 'tier-owner-2@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $manager = User::create(['name' => 'Manager', 'email' => 'tier-manager@example.test',
            'password' => 'test-only']);
        $manager->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $manager->id, 'role' => 'manager']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($manager);
        $this->assertFalse(HallPricingTiers::canAccess());

        $foreignCompany = Company::create(['name' => 'Foreign', 'slug' => 'foreign-tier']);
        $foreignHall = Hall::create(['company_id' => $foreignCompany->id,
            'name' => 'Foreign Hall', 'slug' => 'foreign-tier-hall', 'capacity' => 100]);
        $this->be($owner);
        Livewire::test(HallPricingTiers::class)
            ->set('companyId', $foreignCompany->id)
            ->set('hallId', $foreignHall->id)
            ->assertSet('companyId', $company->id)
            ->assertSet('hallId', $hall->id);
    }}
