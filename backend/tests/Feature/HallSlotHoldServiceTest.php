<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallProgramRulesService;
use App\Services\HallSlotHoldService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallSlotHoldServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $configured = true): array
    {
        $company = Company::create(['name' => 'Silver Venue', 'slug' => 'silver-venue']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Main Hall', 'slug' => 'main',
            'capacity' => 500,
        ]);
        $owner = User::create(['name' => 'Owner', 'email' => 'holds-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->be($owner);

        if ($configured) {
            $slot = static fn ($start, $end) => [
                'start' => $start, 'end' => $end, 'setupMinutes' => 20, 'cleaningMinutes' => 20,
                'graceMinutes' => 15, 'overtimeIntervalMinutes' => 30,
                'overtimeRateMinor' => 5000, 'rentalRateMinor' => 200000,
            ];
            app(HallProgramRulesService::class)->configure($owner, $company->id, $hall->id, [
                'morning' => $slot('08:00', '11:00'),
                'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00'),
            ], 'PKR', 0, 'Owner approved temporary program schedule');
        }

        return [$company, $hall, $owner];
    }

    public function test_same_claim_retry_is_idempotent_and_competing_claim_conflicts(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallSlotHoldService::class);
        $claimId = (string) Str::uuid();
        $first = $service->placeHold($owner, $company->id, $hall->id,
            '2026-11-12', 'noon', $claimId, 15, 'Manager checking guest details');
        $again = $service->placeHold($owner, $company->id, $hall->id,
            '2026-11-12', 'noon', $claimId, 15, 'Retry same hold');
        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('hall_slot_claims')->count());
        $this->assertSame(1, DB::table('hall_slot_claim_events')->where('action', 'hold_created')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'hall_slot.hold_created')->count());

        $this->expectException(ConflictHttpException::class);
        $service->placeHold($owner, $company->id, $hall->id,
            '2026-11-12', 'noon', (string) Str::uuid(), 15, 'Competing event booking');
    }

    public function test_expired_hold_is_released_and_replaced_with_immutable_audit(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallSlotHoldService::class);
        $this->travelTo(now()->startOfMinute());
        try {
            $original = (string) Str::uuid();
            $replacement = (string) Str::uuid();
            $service->placeHold($owner, $company->id, $hall->id,
                '2026-11-12', 'evening', $original, 10, 'Investigating availability');
            $this->travel(11)->minutes();

            $new = $service->placeHold($owner, $company->id, $hall->id,
                '2026-11-12', 'evening', $replacement, 10, 'Second caller after expiry');
            $this->assertSame($replacement, $new['claimId']);
            $this->assertSame(1, DB::table('hall_slot_claims')->count());
            $this->assertSame(3, DB::table('hall_slot_claim_events')->count());
            $this->assertSame(1, DB::table('hall_slot_claim_events')->where('action', 'hold_expired')->count());
            $this->assertSame(1, DB::table('audit_events')->where('action', 'hall_slot.hold_expired')->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_authorized_staff_can_release_own_hold_without_deleting_history(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallSlotHoldService::class);
        $key = (string) Str::uuid();
        $service->placeHold($owner, $company->id, $hall->id,
            '2026-11-12', 'morning', $key, 20, 'Hold for manager review');
        $this->assertTrue($service->releaseOwnHold($owner, $company->id, $hall->id,
            $key, 'Customer did not confirm'));
        $this->assertFalse($service->releaseOwnHold($owner, $company->id, $hall->id,
            $key, 'Safe duplicate release'));
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(2, DB::table('hall_slot_claim_events')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'hall_slot.hold_released')->count());
    }

    public function test_unconfigured_hall_or_foreign_company_cannot_place_hold(): void
    {
        [$company, $hall, $owner] = $this->fixture(false);
        try {
            app(HallSlotHoldService::class)->placeHold($owner, $company->id, $hall->id,
                '2026-11-12', 'noon', (string) Str::uuid(), 15, 'Unconfigured hold attempt');
            $this->fail('Unconfigured hall was holdable');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::table('hall_slot_claims')->count());
        }
        $other = Company::create(['name' => 'Other', 'slug' => 'other']);
        $this->expectException(AuthorizationException::class);
        app(HallSlotHoldService::class)->placeHold($owner, $other->id, $hall->id,
            '2026-11-12', 'noon', (string) Str::uuid(), 15, 'Cross company hold');
    }

    public function test_bad_slot_date_and_expiration_are_rejected_without_insert(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallSlotHoldService::class);
        foreach ([['2026-02-30', 'noon', 10], ['2026-11-12', 'night', 10],
            ['2026-11-12', 'noon', 0], ['2026-11-12', 'noon', 121]] as [$date, $program, $ttl]) {
            try {
                $service->placeHold($owner, $company->id, $hall->id,
                    $date, $program, (string) Str::uuid(), $ttl, 'Reject invalid hold');
                $this->fail('Invalid hold accepted');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, DB::table('hall_slot_claims')->count());
            }
        }
    }

    public function test_audit_failure_rolls_back_claim_and_event(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_claim_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.action = 'hall_slot.hold_created' THEN
        RAISE EXCEPTION 'Injected slot hold audit failure';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_claim_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_claim_audit_test()');
        try {
            app(HallSlotHoldService::class)->placeHold($owner, $company->id, $hall->id,
                '2026-11-12', 'noon', (string) Str::uuid(), 15, 'Atomic hold or nothing');
            $this->fail('Audit failure accepted');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected slot hold audit failure', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_claim_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_claim_audit_test()');
        }
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('hall_slot_claim_events')->count());
    }
    public function test_manager_without_hall_assignment_cannot_place_a_hold(): void
    {
        [$company, $hall] = $this->fixture();
        $manager = User::create([
            'name' => 'Unassigned Manager',
            'email' => 'unassigned-hold-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        $this->expectException(AuthorizationException::class);
        app(HallSlotHoldService::class)->placeHold($manager, $company->id, $hall->id,
            '2026-11-12', 'noon', (string) Str::uuid(), 15, 'Not assigned to this hall');
    }

}
