<?php

namespace Tests\Feature;

use App\Filament\Pages\HallPrograms;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallOvertimePreviewService;
use App\Services\HallProgramRulesService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class HallOvertimePreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Overtime Hall', 'slug' => 'overtime-hall']);
        $hall = Hall::create(['company_id' => $company->id,
            'name' => 'Event Hall', 'slug' => 'event-hall', 'capacity' => 300]);
        $owner = User::create(['name' => 'Overtime Owner',
            'email' => 'overtime-owner@example.test', 'password' => 'synthetic-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $slot = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 20,
            'cleaningMinutes' => 20, 'graceMinutes' => 15,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 5000,
            'rentalRateMinor' => 100000,
        ];
        app(HallProgramRulesService::class)->configure(
            $owner, $company->id, $hall->id, [
                'morning' => $slot('08:00', '11:00'),
                'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00'),
            ], 'PKR', 0, 'Approved synthetic same day clock rules'
        );
        return [$company, $hall, $owner];
    }

    public function test_free_grace_interval_boundary_and_turnover_conflict_do_not_post_money(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallOvertimePreviewService::class);
        $grace = $service->preview($owner, $company->id, $hall->id, 'morning', '11:15');
        $this->assertSame(15, $grace['overtimeMinutes']);
        $this->assertSame(0, $grace['startedIntervals']);
        $this->assertSame(0, $grace['projectedAmountMinor']);
        $this->assertSame('11:20', $grace['hardStopClock']);
        $this->assertFalse($grace['conflictsWithTurnover']);
        $this->assertFalse($grace['extensionAuthorized']);
        $start = $service->preview($owner, $company->id, $hall->id, 'morning', '11:16');
        $this->assertSame(1, $start['startedIntervals']);
        $this->assertSame(5000, $start['projectedAmountMinor']);
        $this->assertFalse($start['conflictsWithTurnover']);
        $conflict = $service->preview($owner, $company->id, $hall->id, 'morning', '11:46');
        $this->assertSame(2, $conflict['startedIntervals']);
        $this->assertSame(10000, $conflict['projectedAmountMinor']);
        $this->assertTrue($conflict['conflictsWithTurnover']);
        $this->assertFalse($conflict['extensionAuthorized']);
        $evening = $service->preview($owner, $company->id, $hall->id, 'evening', '23:41');
        $this->assertSame('23:40', $evening['hardStopClock']);
        $this->assertTrue($evening['conflictsWithTurnover']);
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'hall.program_rules_updated')->count());
    }

    public function test_only_authorized_assigned_hall_staff_can_preview_and_invalid_clock_refused(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $manager = User::create(['name' => 'Overtime Manager',
            'email' => 'overtime-manager@example.test', 'password' => 'synthetic-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create([
            'company_id' => $company->id, 'hall_id' => $hall->id,
            'membership_id' => $membership->id,
        ]);
        $service = app(HallOvertimePreviewService::class);
        $this->be($manager);
        $this->assertSame('internal_nonbinding',
            $service->preview($manager, $company->id, $hall->id, 'noon', '15:45')['status']);
        DB::table('hall_assignments')->where('membership_id', $membership->id)->delete();
        try {
            $service->preview($manager, $company->id, $hall->id, 'noon', '15:45');
            $this->fail('Unassigned staff read a restricted hall overtime preview.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($owner);
        try {
            $service->preview($owner, $company->id, $hall->id, 'morning', '25:00');
            $this->fail('Invalid clock value accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_filament_preview_uses_saved_revision_not_unsaved_price_edits(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        Livewire::test(HallPrograms::class)
            ->assertSee('Grace and overtime safety preview')
            ->set('rules.morning.overtimeRateMinor', 999999)
            ->set('overtimeProgram', 'morning')
            ->set('overtimeObservedEnd', '11:16')
            ->call('calculateOvertimePreview')->assertHasNoErrors()
            ->assertSee('Projected internal amount:')
            ->assertSee('5000 minor units')
            ->assertSee('No turnover conflict in this static simulation.');
    }
}
