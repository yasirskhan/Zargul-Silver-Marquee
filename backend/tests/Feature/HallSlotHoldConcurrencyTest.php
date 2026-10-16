<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallProgramRulesService;
use App\Services\HallSlotHoldService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Two real PostgreSQL connections, not an in-memory/SQLite approximation.
 * DatabaseMigrations keeps committed fixtures visible to both connections.
 */
class HallSlotHoldConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_second_connection_cannot_bypass_hall_lock_and_retry_observes_committed_hold(): void
    {
        $company = Company::create(['name' => 'Lock Test Venue', 'slug' => 'lock-test']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand Hall', 'slug' => 'grand',
            'capacity' => 500,
        ]);
        $owner = User::create([
            'name' => 'Test Owner', 'email' => 'lock-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->be($owner);
        $slot = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 20,
            'cleaningMinutes' => 20, 'graceMinutes' => 15,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 5000,
            'rentalRateMinor' => 200000,
        ];
        app(HallProgramRulesService::class)->configure(
            $owner, $company->id, $hall->id,
            [
                'morning' => $slot('08:00', '11:00'),
                'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00'),
            ],
            'PKR', 0, 'Program configuration for parallel hold test'
        );

        config(['database.connections.second_pgsql' => config('database.connections.pgsql')]);
        DB::purge('second_pgsql');
        $other = DB::connection('second_pgsql');
        $this->assertNotSame(DB::connection()->getPdo(), $other->getPdo());

        // Connection B owns the first row lock. Connection A must not get
        // past the lock into the claim query/insert while B holds it.
        $other->beginTransaction();
        try {
            $locked = $other->table('halls')->where('id', $hall->id)
                ->lockForUpdate()->first(['id']);
            $this->assertSame($hall->id, $locked->id);
            DB::statement("SET lock_timeout = '300ms'");

            try {
                app(HallSlotHoldService::class)->placeHold(
                    $owner, $company->id, $hall->id, '2026-11-12', 'noon',
                    (string) Str::uuid(), 15, 'First connection must wait on locked hall'
                );
                $this->fail('PlaceHold bypassed the PostgreSQL hall lock.');
            } catch (QueryException $error) {
                $this->assertStringContainsString(
                    'lock timeout', strtolower($error->getMessage())
                );
            }
            $this->assertSame(0, $other->table('hall_slot_claims')->count());
        } finally {
            DB::statement('SET lock_timeout TO DEFAULT');
            $other->rollBack();
            DB::disconnect('second_pgsql');
        }

        $service = app(HallSlotHoldService::class);
        $firstKey = (string) Str::uuid();
        $first = $service->placeHold(
            $owner, $company->id, $hall->id, '2026-11-12', 'noon',
            $firstKey, 15, 'Hold after database lock is released'
        );
        $this->assertSame($firstKey, $first['claimId']);
        $this->assertSame(1, DB::table('hall_slot_claims')->count());
        $this->assertSame(1, DB::table('hall_slot_claim_events')->count());

        $this->expectException(ConflictHttpException::class);
        $service->placeHold(
            $owner, $company->id, $hall->id, '2026-11-12', 'noon',
            (string) Str::uuid(), 15, 'Competing claim must not duplicate slot'
        );
    }
}
