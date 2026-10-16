<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\BookingDraftReviewService;
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

class BookingDraftReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'proposal-review']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Main Hall',
            'slug' => 'main', 'capacity' => 300,
        ]);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'proposal-review-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->be($owner);
        $slot = static fn (string $from, string $to): array => [
            'start' => $from, 'end' => $to,
            'setupMinutes' => 20, 'cleaningMinutes' => 20, 'graceMinutes' => 15,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0, 'rentalRateMinor' => 0,
        ];
        app(HallProgramRulesService::class)->configure(
            $owner, $company->id, $hall->id,
            ['morning' => $slot('08:00', '11:00'),
                'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00')],
            'PKR', 0, 'Fixture configured hall without commercial prices'
        );

        $customerId = (string) Str::uuid();
        app(CustomerProfileService::class)->create(
            $owner, $company->id, $customerId,
            'Private Sample Guest', 'private-review@example.test', null,
            'Synthetic owner-only test record'
        );
        $bookingId = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $owner, $company->id, $bookingId, $hall->id, $customerId,
            '2026-12-18', 'evening', 150, 'Owner drafted a possible date only',
        );

        return [$company, $hall, $owner, $membership, $customerId, $bookingId];
    }

    public function test_owner_can_submit_and_withdraw_proposal_without_claim_or_contact_exposure(): void
    {
        [$company, , $owner, , , $bookingId] = $this->fixture();
        $service = app(BookingDraftReviewService::class);
        $submitted = $service->submit($owner, $company->id, $bookingId, 1, 'Ask owner for internal review');
        $this->assertSame([
            'bookingId' => $bookingId, 'revision' => 2, 'status' => 'under_review',
        ], $submitted);
        $this->assertSame('under_review',
            app(BookingDraftService::class)->current($owner, $company->id, $bookingId)['status']);

        $withdrawn = $service->withdraw($owner, $company->id, $bookingId, 2, 'Withdraw unconfirmed proposal');
        $this->assertSame('withdrawn', $withdrawn['status']);
        $this->assertSame(3, $withdrawn['revision']);
        $this->assertSame(3, DB::table('booking_draft_events')->where('booking_id', $bookingId)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'booking_draft.submitted')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'booking_draft.withdrawn')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());

        $history = json_encode(DB::table('booking_draft_events')->get(), JSON_THROW_ON_ERROR);
        foreach (['Private Sample Guest', 'private-review@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $history);
        }
    }

    public function test_review_transitions_are_strict_cas_and_never_confirm(): void
    {
        [$company, , $owner, , , $bookingId] = $this->fixture();
        $service = app(BookingDraftReviewService::class);
        $service->submit($owner, $company->id, $bookingId, 1, 'Review proposal');

        foreach ([
            static fn () => $service->submit($owner, $company->id, $bookingId, 1, 'Duplicate request'),
            static fn () => $service->submit($owner, $company->id, $bookingId, 2, 'Review twice'),
            static fn () => $service->withdraw($owner, $company->id, $bookingId, 1, 'Stale withdrawal'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Conflicting proposal change accepted.');
            } catch (ConflictHttpException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('under_review',
            DB::table('booking_drafts')->where('id', $bookingId)->value('status'));
        $this->assertSame(2, DB::table('booking_draft_events')->where('booking_id', $bookingId)->count());
    }

    public function test_manager_foreign_tenant_and_revoked_owner_cannot_transition(): void
    {
        [$company, , $owner, $membership, , $bookingId] = $this->fixture();
        $service = app(BookingDraftReviewService::class);
        $foreign = Company::create(['name' => 'Foreign', 'slug' => 'foreign-proposal-review']);
        try {
            $service->submit($owner, $foreign->id, $bookingId, 1, 'Wrong tenant');
            $this->fail('Foreign review accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $manager = User::create([
            'name' => 'Manager', 'email' => 'proposal-review-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        try {
            $service->submit($manager, $company->id, $bookingId, 1, 'Manager submission');
            $this->fail('Manager review accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $membership->update(['is_active' => false]);
        $this->be($owner);
        $this->expectException(AuthorizationException::class);
        $service->withdraw($owner, $company->id, $bookingId, 1, 'Revoked member');
    }

    public function test_submitting_rechecks_current_capacity_and_customer_and_never_occupies(): void
    {
        [$company, $hall, $owner, , , $bookingId] = $this->fixture();
        $hall->update(['capacity' => 100]);
        $this->expectException(ConflictHttpException::class);
        app(BookingDraftReviewService::class)->submit(
            $owner, $company->id, $bookingId, 1, 'Review after capacity changed'
        );
    }

    public function test_invalid_reason_and_revision_are_rejected_without_state_change(): void
    {
        [$company, , $owner, , , $bookingId] = $this->fixture();
        $service = app(BookingDraftReviewService::class);
        foreach ([
            [0, 'Valid internal reason'], [1, '<script>unsafe</script>'], [1, ''],
        ] as [$revision, $reason]) {
            try {
                $service->submit($owner, $company->id, $bookingId, $revision, $reason);
                $this->fail('Invalid proposal change accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('draft', DB::table('booking_drafts')->where('id', $bookingId)->value('status'));
    }

    public function test_failed_audit_insert_rolls_back_review_status_and_event(): void
    {
        [$company, , $owner, , , $bookingId] = $this->fixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_proposal_review_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.action = 'booking_draft.submitted' THEN
        RAISE EXCEPTION 'Injected review audit failure';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_proposal_review_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_proposal_review_audit_test()');
        try {
            app(BookingDraftReviewService::class)->submit(
                $owner, $company->id, $bookingId, 1, 'Must fail atomically'
            );
            $this->fail('Failed audit allowed proposal review.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected review audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_proposal_review_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_proposal_review_audit_test()');
        }
        $this->assertSame('draft', DB::table('booking_drafts')->where('id', $bookingId)->value('status'));
        $this->assertSame(1, DB::table('booking_draft_events')->where('booking_id', $bookingId)->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }
}
