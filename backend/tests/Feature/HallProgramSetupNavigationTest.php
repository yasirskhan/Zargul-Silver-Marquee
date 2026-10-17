<?php

namespace Tests\Feature;

use App\Filament\Pages\HallPrograms;
use App\Filament\Pages\VenueOverview;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallProgramRulesService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HallProgramSetupNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerAndHalls(): array
    {
        $company = Company::create(['name' => 'Owner Company', 'slug' => 'setup-primary']);
        $ready = Hall::create(['company_id' => $company->id, 'name' => 'Ready Hall',
            'slug' => 'ready-hall', 'capacity' => 400]);
        $needed = Hall::create(['company_id' => $company->id, 'name' => 'Needs Setup Hall',
            'slug' => 'needs-setup', 'capacity' => 250]);
        $owner = User::create(['name' => 'Owner', 'email' => 'setup-owner@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id,
            'role' => 'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        return [$company, $ready, $needed, $owner];
    }

    private function rules(): array
    {
        $program = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 10,
            'cleaningMinutes' => 10, 'graceMinutes' => 10,
            'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 1000, 'rentalRateMinor' => 10000,
        ];

        return ['morning' => $program('08:00', '11:00'),
            'noon' => $program('12:00', '15:00'),
            'evening' => $program('17:00', '22:00')];
    }

    public function test_owner_overview_opens_only_halls_missing_program_rules(): void
    {
        [$company, $ready, $needed, $owner] = $this->ownerAndHalls();
        app(HallProgramRulesService::class)->configure($owner, $company->id, $ready->id,
            $this->rules(), 'PKR', 0, 'Owner completed first hall');

        Livewire::test(VenueOverview::class)
            ->assertSet('summary.hallsNeedingSetup', 1)
            ->assertSee('Configure halls missing program rules');

        Livewire::withQueryParams(['company' => $company->id, 'setup' => 'needed'])
            ->test(HallPrograms::class)
            ->assertSet('setupFilter', 'needed')
            ->assertSet('hallId', $needed->id)
            ->assertSee('Needs Setup Hall')
            ->assertDontSee('Ready Hall')
            ->call('showAllHalls')
            ->assertSet('setupFilter', '')
            ->assertSee('Ready Hall');

        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_forged_filter_and_foreign_company_are_rejected(): void
    {
        [$company] = $this->ownerAndHalls();
        $foreign = Company::create(['name' => 'Other Private Hall', 'slug' => 'other-setup']);
        Hall::create(['company_id' => $foreign->id, 'name' => 'Private Hall',
            'slug' => 'private-hall', 'capacity' => 100]);

        Livewire::withQueryParams(['company' => $company->id, 'setup' => 'forged'])
            ->test(HallPrograms::class)->assertStatus(422);
        Livewire::withQueryParams(['company' => $foreign->id, 'setup' => 'needed'])
            ->test(HallPrograms::class)->assertForbidden();

        $this->assertSame(0, DB::table('hall_program_rule_revisions')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }

    public function test_successful_program_configuration_clears_filter_without_losing_hall(): void
    {
        [$company, $ready, $needed] = $this->ownerAndHalls();
        Livewire::withQueryParams(['company' => $company->id, 'setup' => 'needed'])
            ->test(HallPrograms::class)
            ->assertSet('hallId', $ready->id)
            ->set('rules', $this->rules())
            ->set('currencyCode', 'PKR')
            ->set('reason', 'Owner completed missing hall rules')
            ->call('saveRules')
            ->assertHasNoErrors()
            ->assertSet('setupFilter', '')
            ->assertSet('hallId', $ready->id)
            ->assertSet('expectedRevision', 1);

        $this->assertSame(1, (int) DB::table('halls')
            ->where('id', $ready->id)->value('program_rules_revision'));
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }
}
