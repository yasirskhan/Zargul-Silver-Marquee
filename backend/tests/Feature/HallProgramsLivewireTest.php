<?php

namespace Tests\Feature;

use App\Filament\Pages\HallPrograms;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallProgramRulesService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HallProgramsLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver Marquee', 'slug' => 'silver-marquee']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand Hall', 'slug' => 'grand',
            'capacity' => 400,
        ]);
        $owner = User::create(['name' => 'Owner', 'email' => 'hall-ui-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        return [$company, $hall, $owner];
    }

    private function schedule(): array
    {
        $make = static fn (string $start, string $end, int $rate): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 20, 'cleaningMinutes' => 20,
            'graceMinutes' => 15, 'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 5000, 'rentalRateMinor' => $rate,
        ];

        return [
            'morning' => $make('08:00', '11:00', 150000),
            'noon' => $make('12:00', '15:00', 180000),
            'evening' => $make('17:00', '22:00', 300000),
        ];
    }

    public function test_hall_programs_company_navigation_uses_authorized_venue_and_revocation_closes_cached_login(): void
    {
        [$first, $firstHall, $owner] = $this->fixture();
        $second = Company::create(['name' => 'Another owned hall', 'slug' => 'hall-ui-owned-second']);
        $secondHall = Hall::create(['company_id' => $second->id,
            'name' => 'Second configured hall', 'slug' => 'second-hall', 'capacity' => 180]);
        CompanyMembership::create(['company_id' => $second->id,
            'user_id' => $owner->id, 'role' => 'owner']);

        $panel = Livewire::test(HallPrograms::class)
            ->assertSet('companyId', $first->id)
            ->set('companyId', $second->id)
            ->assertSet('companyId', $second->id)
            ->assertSet('hallId', $secondHall->id)
            ->assertSee('Second configured hall');
        $this->assertSame($second->id, $panel->get('loadedCompanyId'));
        $this->assertNotSame($firstHall->id, $panel->get('loadedHallId'));

        $private = Company::create(['name' => 'Unowned hall company', 'slug' => 'hall-ui-unowned']);
        Livewire::test(HallPrograms::class)
            ->set('companyId', $private->id)->assertForbidden();

        // A different process may revoke the saved account while this PHP
        // authenticated User object remains verified in the browser session.
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active);
        $this->assertFalse(HallPrograms::canAccess());
        Livewire::test(HallPrograms::class)->assertForbidden();
        DB::table('users')->where('id', $owner->id)
            ->update(['is_active' => true, 'email_verified_at' => null]);
        $this->assertFalse(HallPrograms::canAccess());
        Livewire::test(HallPrograms::class)->assertForbidden();
        $this->assertSame(0, DB::table('hall_program_rule_revisions')->count());
    }

    public function test_cached_owner_account_revocation_blocks_core_hall_rule_and_price_services(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $access = app(\App\Services\TenantAccess::class);
        $this->assertTrue($access->canConfigureHall($owner, $hall));

        // Persist revocation without refreshing the logged-in Eloquent User.
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active);
        $this->assertFalse($access->canConfigureHall($owner, $hall));
        $actions = [
            fn () => app(HallProgramRulesService::class)->configure(
                $owner, $company->id, $hall->id, $this->schedule(),
                'PKR', 0, 'Synthetic blocked program change'
            ),
            fn () => app(\App\Services\HallSeatingPriceService::class)->configure(
                $owner, $company->id, $hall->id, [], 'PKR', 0,
                'Synthetic blocked seating price change'
            ),
            fn () => app(\App\Services\HallAddonCatalogService::class)->configure(
                $owner, $company->id, $hall->id, [], 'PKR', 0,
                'Synthetic blocked catalog change'
            ),
        ];
        foreach ($actions as $change) {
            try {
                $change();
                $this->fail('Stale revoked owner changed an approved hall configuration.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        DB::table('users')->where('id', $owner->id)->update([
            'is_active' => true, 'email_verified_at' => null,
        ]);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse($access->canConfigureHall($owner, $hall));
        try {
            app(HallProgramRulesService::class)->configure(
                $owner, $company->id, $hall->id, $this->schedule(),
                'PKR', 0, 'Synthetic unverified cached owner'
            );
            $this->fail('Cached verification granted a pricing change.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, DB::table('hall_program_rule_revisions')->count());
        $this->assertSame(0, DB::table('hall_seating_price_revisions')->count());
        $this->assertSame(0, DB::table('hall_addon_catalog_revisions')->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_owner_sees_responsive_program_form_and_saves_audited_three_program_revision(): void
    {
        [$company, $hall] = $this->fixture();
        Livewire::test(HallPrograms::class)
            ->assertSet('companyId', $company->id)
            ->assertSet('hallId', $hall->id)
            ->assertSee('Save program rules')
            ->assertSee('Cleaning buffer')
            ->assertSee('Overtime interval')
            ->set('rules', $this->schedule())
            ->set('currencyCode', 'PKR')
            ->set('reason', 'Owner approved initial programs')
            ->call('saveRules')
            ->assertHasNoErrors()
            ->assertSet('expectedRevision', 1)
            ->assertSet('reason', '');

        $this->assertSame(1, (int) DB::table('halls')->where('id', $hall->id)->value('program_rules_revision'));
        $this->assertSame(1, DB::table('hall_program_rule_revisions')->where('hall_id', $hall->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'hall.program_rules_updated')->count());
    }

    public function test_stale_editor_conflict_does_not_overwrite_or_create_audit_and_reload_recovers(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $component = Livewire::test(HallPrograms::class)
            ->set('rules', $this->schedule())
            ->set('currencyCode', 'PKR')
            ->set('reason', 'Set morning noon and evening')
            ->call('saveRules')->assertHasNoErrors();

        $new = $this->schedule();
        $new['evening']['rentalRateMinor'] = 350000;
        app(HallProgramRulesService::class)->configure($owner, $company->id, $hall->id,
            $new, 'PKR', 1, 'Owner changed rate in another session');

        $component->set('reason', 'Attempt stale schedule change')
            ->call('saveRules')->assertHasErrors(['rules'])
            ->assertSet('expectedRevision', 1);

        $this->assertSame(2, DB::table('hall_program_rule_revisions')->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'hall.program_rules_updated')->count());
        $component->call('reloadRules')->assertSet('expectedRevision', 2)
            ->assertSet('rules.evening.rentalRateMinor', 350000);
    }

    public function test_manager_reads_only_assigned_hall_and_cannot_write_price_changes(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        app(HallProgramRulesService::class)->configure($owner, $company->id, $hall->id,
            $this->schedule(), 'PKR', 0, 'Initial approved hall setup');

        $manager = User::create(['name' => 'Manager', 'email' => 'hall-ui-manager@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create(['company_id' => $company->id, 'hall_id' => $hall->id, 'membership_id' => $membership->id]);

        $this->be($manager);
        Livewire::test(HallPrograms::class)
            ->assertSet('hallId', $hall->id)
            ->assertSet('expectedRevision', 1)
            ->assertSee('Assigned hall programs (read-only)')
            ->assertDontSee('Save program rules')
            ->call('saveRules')->assertForbidden();

        $this->assertSame(1, DB::table('hall_program_rule_revisions')->count());
    }

    public function test_foreign_hall_selector_and_revoked_membership_are_rejected(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $foreign = Company::create(['name' => 'Other Company', 'slug' => 'other-company']);
        $foreignHall = Hall::create([
            'company_id' => $foreign->id, 'name' => 'Secret Hall', 'slug' => 'secret',
            'capacity' => 100,
        ]);
        Livewire::test(HallPrograms::class)
            ->assertDontSee('Secret Hall')
            ->set('hallId', $foreignHall->id)->assertForbidden();

        $membership = CompanyMembership::query()->where('user_id', $owner->id)
            ->where('company_id', $company->id)->firstOrFail();
        $membership->update(['is_active' => false]);
        Livewire::test(HallPrograms::class)->assertForbidden();
        $this->assertSame(0, DB::table('hall_program_rule_revisions')->count());
    }

    public function test_incomplete_configuration_never_creates_a_hall_revision(): void
    {
        [, $hall] = $this->fixture();
        Livewire::test(HallPrograms::class)
            ->set('currencyCode', 'PKR')
            ->set('reason', 'Attempt missing times')
            ->call('saveRules')->assertHasErrors();

        $this->assertSame(0, (int) DB::table('halls')->where('id', $hall->id)->value('program_rules_revision'));
        $this->assertSame(0, DB::table('audit_events')->count());
    }
}
