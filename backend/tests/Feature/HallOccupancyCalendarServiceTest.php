<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallOccupancyCalendarService;
use App\Services\HallProgramRulesService;
use App\Services\HallSlotHoldService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class HallOccupancyCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $configured = true): array
    {
        $company = Company::create(['name' => 'Silver Venue', 'slug' => 'calendar-venue']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand Hall',
            'slug' => 'grand', 'capacity' => 500,
        ]);
        $owner = $this->staff('calendar-owner@example.test');
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->be($owner);

        if ($configured) {
            $slot = static fn (string $start, string $end): array => [
                'start' => $start, 'end' => $end,
                'setupMinutes' => 20, 'cleaningMinutes' => 20,
                'graceMinutes' => 15, 'overtimeIntervalMinutes' => 30,
                'overtimeRateMinor' => 5000, 'rentalRateMinor' => 200000,
            ];
            app(HallProgramRulesService::class)->configure($owner, $company->id, $hall->id, [
                'morning' => $slot('08:00', '11:00'),
                'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00'),
            ], 'PKR', 0, 'Configured hall for occupancy projection');
        }

        return [$company, $hall, $owner];
    }

    private function staff(string $email): User
    {
        $staff = User::create(['name' => 'Venue Staff', 'email' => $email, 'password' => 'test-only']);
        $staff->markEmailAsVerified();

        return $staff;
    }

    public function test_configured_hall_emits_exactly_three_programs_per_day_with_inclusive_limits(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $calendar = app(HallOccupancyCalendarService::class)->forHall(
            $owner, $company->id, $hall->id, '2026-11-12', '2026-11-14'
        );
        $this->assertTrue($calendar['configured']);
        $this->assertSame(['2026-11-12', '2026-11-13', '2026-11-14'],
            array_column($calendar['days'], 'date'));
        $this->assertSame([
            'morning' => ['status' => 'available'],
            'noon' => ['status' => 'available'],
            'evening' => ['status' => 'available'],
        ], $calendar['days'][0]['programs']);
        $this->assertCount(3, $calendar['days']);
    }

    public function test_unconfigured_hall_is_never_presented_as_available(): void
    {
        [$company, $hall, $owner] = $this->fixture(false);
        $calendar = app(HallOccupancyCalendarService::class)->forHall(
            $owner, $company->id, $hall->id, '2026-11-12', '2026-11-12'
        );
        $this->assertFalse($calendar['configured']);
        $this->assertSame('unconfigured', $calendar['days'][0]['programs']['noon']['status']);
    }

    public function test_live_hold_confirmed_and_expired_claims_are_distinct_and_projection_does_not_cleanup(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $this->travelTo(now()->startOfMinute());
        try {
            app(HallSlotHoldService::class)->placeHold(
                $owner, $company->id, $hall->id, '2026-11-12', 'morning',
                (string) Str::uuid(), 5, 'Reviewing morning inquiry'
            );
            DB::table('hall_slot_claims')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company->id,
                'hall_id' => $hall->id, 'event_date' => '2026-11-12',
                'program' => 'evening', 'status' => 'confirmed',
                'expires_at' => null, 'created_by' => $owner->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $service = app(HallOccupancyCalendarService::class);
            $before = $service->forHall($owner, $company->id, $hall->id, '2026-11-12', '2026-11-12');
            $this->assertSame('hold', $before['days'][0]['programs']['morning']['status']);
            $this->assertArrayHasKey('expiresAt', $before['days'][0]['programs']['morning']);
            $this->assertSame('available', $before['days'][0]['programs']['noon']['status']);
            $this->assertSame('confirmed', $before['days'][0]['programs']['evening']['status']);

            $events = DB::table('hall_slot_claim_events')->count();
            $audit = DB::table('audit_events')->count();
            $this->travel(5)->minutes();
            $after = $service->forHall($owner, $company->id, $hall->id, '2026-11-12', '2026-11-12');
            $this->assertSame('cleanup_required', $after['days'][0]['programs']['morning']['status']);
            $this->assertSame(2, DB::table('hall_slot_claims')->count());
            $this->assertSame($events, DB::table('hall_slot_claim_events')->count());
            $this->assertSame($audit, DB::table('audit_events')->count());
            $encoded = json_encode($after, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('createdBy', $encoded);
            $this->assertStringNotContainsString('claimId', $encoded);
            $this->assertStringNotContainsString('reason', $encoded);
        } finally {
            $this->travelBack();
        }
    }

    public function test_only_assigned_staff_can_read_hall_and_cross_company_claims_stay_hidden(): void
    {
        [$company, $hall] = $this->fixture();
        $otherHall = Hall::create([
            'company_id' => $company->id, 'name' => 'Second Hall',
            'slug' => 'second', 'capacity' => 250,
        ]);
        $manager = $this->staff('calendar-manager@example.test');
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create([
            'company_id' => $company->id, 'hall_id' => $hall->id,
            'membership_id' => $membership->id,
        ]);
        $this->be($manager);
        $service = app(HallOccupancyCalendarService::class);
        $this->assertCount(1, $service->forHall(
            $manager, $company->id, $hall->id, '2026-11-12', '2026-11-12'
        )['days']);
        foreach ([[$company->id, $otherHall->id], [(string) Str::uuid(), $hall->id]] as [$companyId, $hallId]) {
            try {
                $service->forHall($manager, $companyId, $hallId, '2026-11-12', '2026-11-12');
                $this->fail('Cross-hall/company occupancy was exposed.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $membership->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $service->forHall($manager, $company->id, $hall->id, '2026-11-12', '2026-11-12');
    }

    public function test_logged_in_actor_and_verified_active_membership_are_required(): void
    {
        [$company, $hall] = $this->fixture();
        $imposter = $this->staff('calendar-imposter@example.test');
        $this->expectException(AuthorizationException::class);
        app(HallOccupancyCalendarService::class)->forHall(
            $imposter, $company->id, $hall->id, '2026-11-12', '2026-11-12'
        );
    }

    public function test_invalid_calendar_dates_and_large_ranges_are_refused(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        foreach ([
            ['2026-02-30', '2026-03-01'], ['2026-11-13', '2026-11-12'],
            ['2026-11-01', '2026-12-13'], ['2026-11-12 00:00', '2026-11-12'],
        ] as [$from, $to]) {
            try {
                app(HallOccupancyCalendarService::class)->forHall(
                    $owner, $company->id, $hall->id, $from, $to
                );
                $this->fail('Invalid calendar range was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
