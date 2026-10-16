<?php

namespace Tests\Feature;

use App\Filament\Pages\HallPrograms;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallAddonCatalogService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HallProgramsAddonsLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Zargul', 'slug' => 'zargul']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Silver', 'slug' => 'silver', 'capacity' => 500,
        ]);
        $owner = User::create(['name' => 'Owner', 'email' => 'addon-ui-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        return [$company, $hall, $owner];
    }

    private function items(): array
    {
        return [
            ['code' => 'catering_a', 'name' => 'Guest meal', 'category' => 'catering',
                'chargeUnit' => 'per_guest', 'rateMinor' => 15000, 'enabled' => true],
        ];
    }

    public function test_owner_adds_explicit_service_without_fabricated_price_and_saves_catalog(): void
    {
        [$company, $hall] = $this->fixture();
        Livewire::test(HallPrograms::class)
            ->assertSet('addons', [])
            ->assertSee('Add optional service')
            ->call('addAddon')
            ->assertSet('addons.0.rateMinor', '')
            ->set('addons', $this->items())
            ->set('addonCurrencyCode', 'PKR')
            ->set('addonReason', 'Approved optional catering package')
            ->call('saveAddons')->assertHasNoErrors()
            ->assertSet('expectedAddonRevision', 1);

        $this->assertSame(1, DB::table('hall_addon_catalog_revisions')->where('hall_id', $hall->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'hall.addon_catalog_updated')->count());
        $this->assertSame(0, DB::table('hall_program_rule_revisions')->count());
    }

    public function test_addon_stale_save_fails_and_reload_preserves_separate_rules(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $component = Livewire::test(HallPrograms::class)
            ->set('addons', $this->items())->set('addonCurrencyCode', 'PKR')
            ->set('addonReason', 'Owner saved catalog')
            ->call('saveAddons')->assertHasNoErrors();

        $next = $this->items();
        $next[0]['rateMinor'] = 19000;
        app(HallAddonCatalogService::class)->configure(
            $owner, $company->id, $hall->id, $next, 'PKR', 1, 'Second editor updated catalog',
        );
        $component->set('addonReason', 'Attempt stale change')
            ->call('saveAddons')->assertHasErrors(['addons'])
            ->assertSet('expectedAddonRevision', 1);
        $this->assertSame(2, DB::table('hall_addon_catalog_revisions')->count());
        $component->call('reloadRules')
            ->assertSet('expectedAddonRevision', 2)
            ->assertSet('addons.0.rateMinor', 19000)
            ->assertSet('expectedRevision', 0)
            ->assertSet('expectedSeatingRevision', 0);
    }

    public function test_manager_reads_approved_services_but_cannot_edit(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        app(HallAddonCatalogService::class)->configure(
            $owner, $company->id, $hall->id, $this->items(), 'PKR', 0, 'Owner approved catalog',
        );
        $manager = User::create(['name' => 'Manager', 'email' => 'addon-ui-manager@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create(['company_id' => $company->id, 'hall_id' => $hall->id, 'membership_id' => $membership->id]);
        $this->be($manager);

        Livewire::test(HallPrograms::class)
            ->assertSet('expectedAddonRevision', 1)
            ->assertSee('Assigned hall catalog is read-only')
            ->assertDontSee('Save optional services')
            ->call('saveAddons')->assertForbidden();
        $this->assertSame(1, DB::table('hall_addon_catalog_revisions')->count());
    }

    public function test_unsaved_new_item_never_changes_the_approved_catalog(): void
    {
        [, $hall] = $this->fixture();
        Livewire::test(HallPrograms::class)
            ->call('addAddon')
            ->assertSet('addons.0.rateMinor', '')
            ->assertSet('expectedAddonRevision', 0);

        $this->assertSame(0, DB::table('hall_addon_catalog_revisions')->where('hall_id', $hall->id)->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }
}
