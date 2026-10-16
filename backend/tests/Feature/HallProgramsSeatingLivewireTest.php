<?php

namespace Tests\Feature;

use App\Filament\Pages\HallPrograms;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallSeatingPriceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HallProgramsSeatingLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Zargul', 'slug' => 'zargul']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Silver', 'slug' => 'silver',
            'capacity' => 500, 'included_chairs' => 300, 'included_tables' => 30,
        ]);
        $owner = User::create(['name' => 'Owner', 'email' => 'seating-livewire@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        return [$company, $hall, $owner];
    }

    private function config(): array
    {
        return [
            'capacity' => 500, 'includedChairs' => 300, 'includedTables' => 30,
            'extraChairRateMinor' => 2500, 'extraTableRateMinor' => 12000,
        ];
    }

    public function test_owner_uses_same_company_hall_selector_to_save_seating_rates(): void
    {
        [$company, $hall] = $this->fixture();
        Livewire::test(HallPrograms::class)
            ->assertSet('hallId', $hall->id)
            ->assertSet('seating.capacity', 500)
            ->assertSet('seating.extraChairRateMinor', '')
            ->assertSee('Save seating rules')
            ->set('seating', $this->config())
            ->set('seatingCurrencyCode', 'PKR')
            ->set('seatingReason', 'Owner approved extra chairs and tables')
            ->call('saveSeating')->assertHasNoErrors()
            ->assertSet('expectedSeatingRevision', 1)
            ->assertSet('seatingReason', '');

        $this->assertSame(1, DB::table('hall_seating_price_revisions')->where('hall_id', $hall->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'hall.seating_prices_updated')->count());
        $this->assertSame(0, DB::table('hall_program_rule_revisions')->count());
    }

    public function test_stale_seating_editor_reloads_without_losing_independent_program_token(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $component = Livewire::test(HallPrograms::class)
            ->set('seating', $this->config())->set('seatingCurrencyCode', 'PKR')
            ->set('seatingReason', 'Initial seating prices')
            ->call('saveSeating')->assertHasNoErrors();

        $new = $this->config();
        $new['extraChairRateMinor'] = 2700;
        app(HallSeatingPriceService::class)->configure(
            $owner, $company->id, $hall->id, $new, 'PKR', 1,
            'Second session updated extra chair rate',
        );

        $component->set('seatingReason', 'Try stale price')
            ->call('saveSeating')->assertHasErrors(['seating'])
            ->assertSet('expectedSeatingRevision', 1);
        $this->assertSame(2, DB::table('hall_seating_price_revisions')->count());
        $component->call('reloadRules')
            ->assertSet('expectedSeatingRevision', 2)
            ->assertSet('seating.extraChairRateMinor', 2700)
            ->assertSet('expectedRevision', 0);
    }

    public function test_assigned_manager_has_read_only_seating_and_no_submit_authority(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        app(HallSeatingPriceService::class)->configure(
            $owner, $company->id, $hall->id, $this->config(), 'PKR', 0, 'Approved rates',
        );
        $manager = User::create(['name' => 'Manager', 'email' => 'seating-manager-livewire@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create([
            'company_id' => $company->id, 'hall_id' => $hall->id, 'membership_id' => $membership->id,
        ]);
        $this->be($manager);

        Livewire::test(HallPrograms::class)
            ->assertSee('Assigned hall seating is read-only')
            ->assertDontSee('Save seating rules')
            ->assertSet('seating.extraChairRateMinor', 2500)
            ->call('saveSeating')->assertForbidden();

        $this->assertSame(1, DB::table('hall_seating_price_revisions')->count());
    }

    public function test_blank_additional_rates_cannot_be_silently_saved_as_zero(): void
    {
        [, $hall] = $this->fixture();
        Livewire::test(HallPrograms::class)
            ->assertSet('seating.extraChairRateMinor', '')
            ->set('seatingCurrencyCode', 'PKR')
            ->set('seatingReason', 'Incomplete submitted prices')
            ->call('saveSeating')->assertHasErrors();

        $this->assertSame(0, DB::table('hall_seating_price_revisions')->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }
}
