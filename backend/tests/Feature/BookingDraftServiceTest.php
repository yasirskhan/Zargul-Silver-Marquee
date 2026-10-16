<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\BookingDraftService;
use App\Services\CustomerProfileService;
use App\Services\HallProgramRulesService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class BookingDraftServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $configured = true): array
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'internal-proposals']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Silver Hall',
            'slug' => 'silver', 'capacity' => 300,
        ]);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'internal-proposal-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->be($owner);

        if ($configured) {
            $slot = static fn (string $start, string $end): array => [
                'start' => $start, 'end' => $end,
                'setupMinutes' => 20, 'cleaningMinutes' => 20, 'graceMinutes' => 15,
                'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
                'rentalRateMinor' => 0,
            ];
            app(HallProgramRulesService::class)->configure(
                $owner, $company->id, $hall->id,
                ['morning' => $slot('08:00', '11:00'),
                    'noon' => $slot('12:00', '15:00'),
                    'evening' => $slot('17:00', '22:00')],
                'PKR', 0, 'Owner setup for internal draft tests',
            );
        }

        $customerId = (string) Str::uuid();
        app(CustomerProfileService::class)->create(
            $owner, $company->id, $customerId,
            'Sample Contact', 'confidential@example.test', '+1 555 123 4321',
            'Owner only test fixture, no live PII',
        );

        return [$company, $hall, $owner, $membership, $customerId];
    }

    private function createDraft(
        User $owner, string $companyId, string $id,
        string $hallId, string $customerId, int $guests = 100,
    ): array {
        return app(BookingDraftService::class)->create(
            $owner, $companyId, $id, $hallId, $customerId,
            '2026-12-16', 'evening', $guests, 'Internal planning draft, not a reservation',
        );
    }

    public function test_owner_create_idempotent_retry_preserves_redacted_history_and_does_not_claim_slot(): void
    {
        [$company, $hall, $owner, , $customerId] = $this->fixture();
        $id = (string) Str::uuid();
        $first = $this->createDraft($owner, $company->id, $id, $hall->id, $customerId);
        $again = $this->createDraft($owner, $company->id, $id, $hall->id, $customerId);
        $this->assertSame($first, $again);
        $this->assertSame(['bookingId' => $id, 'revision' => 1, 'status' => 'draft'], $first);
        $this->assertSame(1, DB::table('booking_drafts')->count());
        $this->assertSame(1, DB::table('booking_draft_events')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'booking_draft.created')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('hall_slot_claim_events')->count());

        $current = app(BookingDraftService::class)->current($owner, $company->id, $id);
        $this->assertSame(100, $current['guests']);
        $this->assertSame($customerId, $current['customerId']);

        $history = json_encode([
            DB::table('booking_draft_events')->first(),
            DB::table('audit_events')->where('action', 'booking_draft.created')->first(),
            $current,
        ], JSON_THROW_ON_ERROR);
        foreach (['Sample Contact', 'confidential@example.test', '+1 555 123 4321'] as $secret) {
            $this->assertStringNotContainsString($secret, $history);
        }
    }

    public function test_owner_revision_update_is_locked_and_refuses_stale_or_different_create_retries(): void
    {
        [$company, $hall, $owner, , $customerId] = $this->fixture();
        $id = (string) Str::uuid();
        $this->createDraft($owner, $company->id, $id, $hall->id, $customerId);
        $second = app(BookingDraftService::class)->update(
            $owner, $company->id, $id, $hall->id, $customerId,
            '2026-12-17', 'noon', 150, 1, 'Customer planning details changed internally',
        );
        $this->assertSame(2, $second['revision']);
        $this->assertSame('2026-12-17',
            app(BookingDraftService::class)->current($owner, $company->id, $id)['date']);
        $this->assertSame(2, DB::table('booking_draft_events')->count());

        try {
            app(BookingDraftService::class)->update(
                $owner, $company->id, $id, $hall->id, $customerId,
                '2026-12-18', 'morning', 80, 1, 'Stale editor correction',
            );
            $this->fail('Stale revision overwritten.');
        } catch (ConflictHttpException) {
            $this->assertSame('2026-12-17',
                app(BookingDraftService::class)->current($owner, $company->id, $id)['date']);
        }

        $this->expectException(ConflictHttpException::class);
        $this->createDraft($owner, $company->id, $id, $hall->id, $customerId);
    }

    public function test_foreign_customer_manager_and_revoked_owner_are_denied(): void
    {
        [$company, $hall, $owner, $membership, $customerId] = $this->fixture();
        $foreign = Company::create(['name' => 'Foreign', 'slug' => 'proposal-foreign']);
        $service = app(BookingDraftService::class);
        try {
            $service->create($owner, $foreign->id, (string) Str::uuid(),
                $hall->id, $customerId, '2026-12-18', 'morning', 10, 'No foreign tenant access');
            $this->fail('Foreign company accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $manager = User::create([
            'name' => 'Manager', 'email' => 'proposal-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        try {
            $service->create($manager, $company->id, (string) Str::uuid(),
                $hall->id, $customerId, '2026-12-18', 'morning', 10, 'Manager attempt');
            $this->fail('Manager accessed owner proposal.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $membership->update(['is_active' => false]);
        $this->be($owner);
        $this->expectException(AuthorizationException::class);
        $service->create($owner, $company->id, (string) Str::uuid(),
            $hall->id, $customerId, '2026-12-18', 'morning', 10, 'Revoked attempt');
    }

    public function test_invalid_capacity_date_and_reason_fail_without_creating_a_booking(): void
    {
        [$company, $hall, $owner, , $customerId] = $this->fixture();
        $service = app(BookingDraftService::class);
        foreach ([
            ['2026-02-30', 'evening', 100, 'Internal plan'],
            ['2026-12-16', 'unknown', 100, 'Internal plan'],
            ['2026-12-16', 'evening', 301, 'Internal plan'],
            ['2026-12-16', 'evening', 0, 'Internal plan'],
            ['2026-12-16', 'evening', 100, '<script>bad</script>'],
        ] as [$date, $program, $count, $reason]) {
            try {
                $service->create($owner, $company->id, (string) Str::uuid(),
                    $hall->id, $customerId, $date, $program, $count, $reason);
                $this->fail('Invalid internal proposal accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, DB::table('booking_drafts')->count());
        $this->assertSame(0, DB::table('booking_draft_events')->count());
    }

    public function test_unconfigured_hall_or_missing_customer_does_not_create_draft(): void
    {
        [$company, $hall, $owner, , $customerId] = $this->fixture(false);
        try {
            $this->createDraft($owner, $company->id, (string) Str::uuid(), $hall->id, $customerId);
            $this->fail('Unconfigured hall accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, DB::table('booking_drafts')->count());
    }

    public function test_audit_failure_rolls_back_draft_and_immutable_event(): void
    {
        [$company, $hall, $owner, , $customerId] = $this->fixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_proposal_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.action = 'booking_draft.created' THEN
        RAISE EXCEPTION 'Injected booking draft audit failure';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_proposal_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_proposal_audit_test()');
        try {
            $this->createDraft($owner, $company->id, (string) Str::uuid(), $hall->id, $customerId);
            $this->fail('Audit failure accepted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected booking draft audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_proposal_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_proposal_audit_test()');
        }
        $this->assertSame(0, DB::table('booking_drafts')->count());
        $this->assertSame(0, DB::table('booking_draft_events')->count());
    }
}
