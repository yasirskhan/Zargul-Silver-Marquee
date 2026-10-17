<?php

namespace Tests\Feature;

use App\Filament\Pages\HallCalendar;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallProgramRulesService;
use App\Services\HallSlotHoldService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class HallCalendarLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $configured = true): array
    {
        $company = Company::create(['name' => 'Silver Marquee', 'slug' => 'calendar-ui']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand Hall',
            'slug' => 'grand', 'capacity' => 500,
        ]);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'calendar-ui-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        if ($configured) {
            $slot = static fn (string $start, string $end): array => [
                'start' => $start, 'end' => $end, 'setupMinutes' => 20,
                'cleaningMinutes' => 20, 'graceMinutes' => 15,
                'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 5000,
                'rentalRateMinor' => 200000,
            ];
            app(HallProgramRulesService::class)->configure(
                $owner, $company->id, $hall->id,
                ['morning' => $slot('08:00', '11:00'),
                    'noon' => $slot('12:00', '15:00'),
                    'evening' => $slot('17:00', '22:00')],
                'PKR', 0, 'Owner configured calendar hall rules'
            );
        }

        return [$company, $hall, $owner];
    }


    public function test_stale_owner_account_revocation_blocks_private_hall_calendar_read(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $this->assertTrue(HallCalendar::canAccess());

        // Current persisted user state overrides a previously verified Auth model.
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active);
        $this->assertFalse(HallCalendar::canAccess());
        Livewire::test(HallCalendar::class)->assertForbidden();

        DB::table('users')->where('id', $owner->id)
            ->update(['is_active' => true, 'email_verified_at' => null]);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse(HallCalendar::canAccess());
        Livewire::test(HallCalendar::class)->assertForbidden();
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('hall_slot_claim_events')->count());
    }

    public function test_today_button_returns_to_current_calendar_date_without_writing_claims(): void
    {
        [$company, $hall] = $this->fixture();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-18 13:00:00', 'UTC'));
        try {
            Livewire::test(HallCalendar::class)
                ->set('viewMode', 'day')
                ->set('anchorDate', '2027-01-09')
                ->call('jumpToToday')
                ->assertSet('anchorDate', '2026-11-18')
                ->assertSet('projection.fromDate', '2026-11-18')
                ->assertSet('projection.toDate', '2026-11-18')
                ->assertSee('Today');
            $this->assertSame(0, DB::table('hall_slot_claims')->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_owner_sees_active_draft_counts_by_program_without_treating_drafts_as_holds(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $customer = (string) Str::uuid();
        app(\App\Services\CustomerProfileService::class)->create(
            $owner, $company->id, $customer,
            'Private test customer', 'draft-counter@example.test', null, 'Synthetic calendar test only'
        );
        $morningId = (string) Str::uuid();
        $noonId = (string) Str::uuid();
        app(\App\Services\BookingDraftService::class)->create(
            $owner, $company->id, $morningId, $hall->id, $customer,
            '2026-12-15', 'morning', 120, 'Unconfirmed morning booking proposal'
        );
        app(\App\Services\BookingDraftService::class)->create(
            $owner, $company->id, $noonId, $hall->id, $customer,
            '2026-12-15', 'noon', 90, 'Unconfirmed noon booking proposal'
        );

        $view = Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2026-12-15')->set('viewMode', 'day')
            ->assertSee('1 unconfirmed')
            ->assertSee('(not reserved)')
            ->assertSet('projection.days.0.programs.morning.status', 'available')
            ->assertSet('projection.days.0.programs.noon.status', 'available');
        $this->assertSame(1, $view->get('proposalCounts')['2026-12-15']['morning']);
        $this->assertSame(1, $view->get('proposalCounts')['2026-12-15']['noon']);
        $this->assertSame(0, DB::table('hall_slot_claims')->count());

        $manager = User::create(['name' => 'Staff manager',
            'email' => 'draft-counter-manager@example.test', 'password' => 'synthetic']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create([
            'company_id' => $company->id, 'hall_id' => $hall->id,
            'membership_id' => $membership->id,
        ]);
        $this->be($manager);
        Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2026-12-15')->set('viewMode', 'day')
            ->assertSet('proposalCounts', [])
            ->assertDontSee('(not reserved)')
            ->assertDontSee('View draft proposals');
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }

    public function test_owner_calendar_links_day_to_private_proposals_but_manager_cannot_open_drafts(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2026-12-15')
            ->set('viewMode', 'day')
            ->assertSee('View draft proposals')
            ->assertSee('Draft proposals are separate from physical slot claims');
        $this->assertSame(0, DB::table('hall_slot_claims')->count());

        $manager = User::create([
            'name' => 'Calendar manager',
            'email' => 'calendar-link-manager@example.test',
            'password' => 'synthetic-only',
        ]);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id,
            'role' => 'manager',
        ]);
        HallAssignment::create([
            'company_id' => $company->id, 'hall_id' => $hall->id,
            'membership_id' => $membership->id,
        ]);
        $this->be($manager);
        Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2026-12-15')
            ->set('viewMode', 'day')
            ->assertSee('Internal, read-only hall occupancy')
            ->assertDontSee('View draft proposals');
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }

    public function test_owner_can_switch_day_week_month_and_navigate_without_mutation(): void
    {
        [$company, $hall] = $this->fixture();
        $component = Livewire::test(HallCalendar::class)
            ->assertSet('companyId', $company->id)
            ->assertSet('hallId', $hall->id)
            ->assertSee('Internal, read-only hall occupancy')
            ->set('anchorDate', '2026-11-12')
            ->call('reloadCalendar')
            ->assertSet('projection.fromDate', '2026-11-09')
            ->assertSet('projection.toDate', '2026-11-15')
            ->set('viewMode', 'day')
            ->assertSet('projection.fromDate', '2026-11-12')
            ->assertSet('projection.toDate', '2026-11-12')
            ->set('viewMode', 'month')
            ->assertSet('projection.fromDate', '2026-11-01')
            ->assertSet('projection.toDate', '2026-11-30')
            ->call('stepPeriod', 1)
            ->assertSet('projection.fromDate', '2026-12-01')
            ->assertSet('projection.toDate', '2026-12-31');

        $this->assertCount(31, $component->get('projection')['days']);
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('hall_slot_claim_events')->count());
    }

    public function test_active_hold_and_expired_still_occupied_are_visible_without_customer_data(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $this->travelTo(now()->startOfMinute());
        try {
            app(HallSlotHoldService::class)->placeHold(
                $owner, $company->id, $hall->id, '2026-11-12',
                'noon', (string) Str::uuid(), 10, 'Staff tentative planning'
            );
            $component = Livewire::test(HallCalendar::class)
                ->set('anchorDate', '2026-11-12')
                ->set('viewMode', 'day')
                ->assertSet('projection.days.0.programs.noon.status', 'hold');

            $this->travel(11)->minutes();
            $component->call('reloadCalendar')
                ->assertSet('projection.days.0.programs.noon.status', 'cleanup_required')
                ->assertSee('Cleanup required');
            $this->assertSame(1, DB::table('hall_slot_claims')->count());
            $this->assertSame(1, DB::table('hall_slot_claim_events')->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_manager_sees_assigned_hall_only_and_revoked_membership_blocks_panel(): void
    {
        [$company, $hall] = $this->fixture();
        $foreignHall = Hall::create([
            'company_id' => $company->id, 'name' => 'Private Garden',
            'slug' => 'garden', 'capacity' => 200,
        ]);
        $manager = User::create([
            'name' => 'Manager', 'email' => 'calendar-ui-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create([
            'company_id' => $company->id, 'hall_id' => $hall->id,
            'membership_id' => $membership->id,
        ]);
        $this->be($manager);
        Livewire::test(HallCalendar::class)
            ->assertSet('hallId', $hall->id)
            ->assertDontSee('Private Garden')
            ->set('hallId', $foreignHall->id)->assertForbidden();

        $membership->update(['is_active' => false]);
        Livewire::test(HallCalendar::class)->assertForbidden();
    }

    public function test_unverified_owner_and_invalid_anchor_are_rejected(): void
    {
        [$company, $hall, $owner] = $this->fixture(false);
        Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2026-02-30')
            ->call('reloadCalendar')
            ->assertHasErrors(['anchorDate']);
        $owner->forceFill(['email_verified_at' => null])->save();
        Livewire::test(HallCalendar::class)->assertForbidden();
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }
}
