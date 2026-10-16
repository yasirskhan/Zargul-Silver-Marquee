<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallProgramRulesService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallProgramRulesServiceTest extends TestCase
{
    use RefreshDatabase;

    private function setupHall(): array
    {
        $company = Company::create(['name' => 'Silver Hall', 'slug' => 'silver-hall']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand', 'slug' => 'grand',
            'capacity' => 500, 'included_chairs' => 300, 'included_tables' => 30,
        ]);
        $owner = User::create(['name' => 'Owner', 'email' => 'hall-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->be($owner);

        return [$company, $hall, $owner];
    }

    private function schedule(): array
    {
        return [
            'morning' => $this->slot('08:00', '11:00', 150000),
            'noon' => $this->slot('12:00', '15:00', 180000),
            'evening' => $this->slot('17:00', '22:00', 300000),
        ];
    }

    private function slot(string $start, string $end, int $rate): array
    {
        return [
            'start' => $start, 'end' => $end,
            'setupMinutes' => 20, 'cleaningMinutes' => 20,
            'graceMinutes' => 15, 'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 5000, 'rentalRateMinor' => $rate,
        ];
    }

    public function test_owner_can_version_all_three_programs_with_atomic_audit_and_snapshot(): void
    {
        [$company, $hall, $owner] = $this->setupHall();
        $service = app(HallProgramRulesService::class);
        $this->assertNull($service->current($owner, $company->id, $hall->id));

        $this->assertSame(1, $service->configure($owner, $company->id, $hall->id,
            $this->schedule(), 'PKR', 0, 'Approved initial program timetable'));
        $saved = $service->current($owner, $company->id, $hall->id);
        $this->assertSame(1, $saved['revision']);
        $this->assertSame('PKR', $saved['currencyCode']);
        $this->assertEquals($this->schedule(), $saved['rules']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'hall.program_rules_updated')->count());

        $next = $this->schedule();
        $next['evening']['rentalRateMinor'] = 340000;
        $this->assertSame(2, $service->configure($owner, $company->id, $hall->id,
            $next, 'PKR', 1, 'Owner raised evening rental rate'));
        $this->assertSame(2, DB::table('hall_program_rule_revisions')->count());
        $this->assertSame(2, (int) DB::table('halls')->where('id', $hall->id)->value('program_rules_revision'));
        $this->assertSame(2, DB::table('audit_events')->count());
        $this->assertEquals($this->schedule(), json_decode(DB::table('hall_program_rule_revisions')
            ->where('hall_id', $hall->id)->where('revision_number', 1)->value('rules'), true));
    }

    public function test_manager_cannot_change_rates_but_can_read_assigned_hall_programs(): void
    {
        [$company, $hall, $owner] = $this->setupHall();
        $service = app(HallProgramRulesService::class);
        $service->configure($owner, $company->id, $hall->id,
            $this->schedule(), 'PKR', 0, 'Configure approved programs');
        $manager = User::create(['name' => 'Manager', 'email' => 'hall-manager@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create(['company_id' => $company->id, 'hall_id' => $hall->id, 'membership_id' => $membership->id]);
        $this->be($manager);
        $this->assertSame(1, $service->current($manager, $company->id, $hall->id)['revision']);
        $this->expectException(AuthorizationException::class);
        $service->configure($manager, $company->id, $hall->id,
            $this->schedule(), 'PKR', 1, 'Manager unauthorized price override');
    }

    public function test_overlaps_with_buffers_and_invalid_time_are_rejected_without_audit(): void
    {
        [$company, $hall, $owner] = $this->setupHall();
        $schedule = $this->schedule();
        $schedule['noon']['start'] = '11:10'; // Morning cleans until 11:20; noon setup begins 10:50.
        try {
            app(HallProgramRulesService::class)->configure($owner, $company->id, $hall->id,
                $schedule, 'PKR', 0, 'Invalid conflicting timetable');
            $this->fail('Overlapping setup and cleaning accepted');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('hall_program_rule_revisions')->count());
            $this->assertSame(0, DB::table('audit_events')->count());
        }

        $schedule = $this->schedule();
        $schedule['morning']['start'] = '25:00';
        $this->expectException(InvalidArgumentException::class);
        app(HallProgramRulesService::class)->configure($owner, $company->id, $hall->id,
            $schedule, 'PKR', 0, 'Invalid HHMM');
    }

    public function test_stale_revision_and_foreign_company_change_cannot_overwrite(): void
    {
        [$company, $hall, $owner] = $this->setupHall();
        $service = app(HallProgramRulesService::class);
        $service->configure($owner, $company->id, $hall->id,
            $this->schedule(), 'PKR', 0, 'Initial schedule');
        try {
            $service->configure($owner, $company->id, $hall->id,
                $this->schedule(), 'PKR', 0, 'Stale previous screen');
            $this->fail('Stale revision was accepted');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('hall_program_rule_revisions')->count());
        }

        $other = Company::create(['name' => 'Other', 'slug' => 'other-hall']);
        $this->expectException(AuthorizationException::class);
        $service->configure($owner, $other->id, $hall->id,
            $this->schedule(), 'PKR', 1, 'Cross-company attempt');
    }

    public function test_audit_failure_rolls_back_configuration_and_hall_pointer(): void
    {
        [$company, $hall, $owner] = $this->setupHall();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_hall_program_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Injected hall audit failure';
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_hall_program_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_hall_program_audit_test()');
        try {
            app(HallProgramRulesService::class)->configure($owner, $company->id, $hall->id,
                $this->schedule(), 'PKR', 0, 'Audit must commit atomically');
            $this->fail('Audit failure accepted');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected hall audit failure', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_hall_program_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_hall_program_audit_test()');
        }
        $this->assertSame(0, (int) DB::table('halls')->where('id', $hall->id)->value('program_rules_revision'));
        $this->assertSame(0, DB::table('hall_program_rule_revisions')->count());
    }
}
