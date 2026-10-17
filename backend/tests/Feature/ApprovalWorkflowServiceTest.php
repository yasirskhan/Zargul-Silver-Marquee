<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\BookingDraftReviewService;
use App\Services\BookingDraftService;
use App\Services\CustomerProfileService;
use App\Services\HallProgramRulesService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ApprovalWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'approvals-fixture']);
        $foreign = Company::create(['name' => 'Other', 'slug' => 'other-approvals-fixture']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Grand', 'slug' => 'grand', 'capacity' => 250]);
        $people = [];
        foreach (['owner', 'admin', 'manager', 'reception'] as $role) {
            $person = User::create([
                'name' => ucfirst($role),
                'email' => 'approval-'.$role.'@example.test', 'password' => 'test-only',
            ]);
            $person->markEmailAsVerified();
            $membership = CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $person->id, 'role' => $role,
            ]);
            $people[$role] = [$person, $membership];
        }
        $this->be($people['owner'][0]);
        $slot = static fn (string $a, string $b): array => [
            'start' => $a, 'end' => $b, 'setupMinutes' => 10,
            'cleaningMinutes' => 10, 'graceMinutes' => 10,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
            'rentalRateMinor' => 0,
        ];
        app(HallProgramRulesService::class)->configure(
            $people['owner'][0], $company->id, $hall->id,
            ['morning' => $slot('08:00', '11:00'), 'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00')],
            'PKR', 0, 'Only synthetic test fixture'
        );
        $customer = (string) Str::uuid();
        app(CustomerProfileService::class)->create(
            $people['owner'][0], $company->id, $customer,
            'Fake Contact', 'fake-fixture@example.test', null, 'Synthetic test only'
        );
        $bookingId = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $people['owner'][0], $company->id, $bookingId, $hall->id,
            $customer, '2027-01-02', 'evening', 120, 'Synthetic possible event'
        );
        app(BookingDraftReviewService::class)->submit(
            $people['owner'][0], $company->id, $bookingId, 1, 'Internal review only'
        );
        HallAssignment::create([
            'company_id' => $company->id, 'hall_id' => $hall->id,
            'membership_id' => $people['manager'][1]->id,
        ]);

        return [$company, $foreign, $hall, $people, $bookingId];
    }

    public function test_cached_owner_account_revocation_blocks_inbox_and_attention(): void
    {
        [$company, , , $people] = $this->fixture();
        $owner = $people['owner'][0];
        $service = app(ApprovalWorkflowService::class);
        $this->assertIsArray($service->inbox($owner, $company->id));
        $this->assertIsArray($service->ownerPendingAttention($owner));

        foreach ([
            ['is_active' => false],
            ['is_active' => true, 'email_verified_at' => null],
        ] as $newState) {
            DB::table('users')->where('id', $owner->id)->update($newState);
            foreach ([
                fn () => $service->inbox($owner, $company->id),
                fn () => $service->ownerPendingAttention($owner),
            ] as $read) {
                try {
                    $read();
                    $this->fail('Stale authenticated owner bypassed account revocation.');
                } catch (AuthorizationException) {
                    $this->assertTrue(true);
                }
            }
        }
        $this->assertSame(0, DB::table('approval_decisions')->count());
    }

    public function test_cached_revoked_owner_cannot_request_or_decide_approval(): void
    {
        [$company, , , $people] = $this->fixture();
        $service = app(ApprovalWorkflowService::class);
        $admin = $people['admin'][0];
        $owner = $people['owner'][0];
        $this->be($admin);
        $pending = (string) Str::uuid();
        $service->request($admin, $company->id, $pending, 'expense',
            175, 'PKR', null, 'Synthetic maintenance approval');
        $this->be($owner);

        foreach ([
            ['is_active' => false],
            ['is_active' => true, 'email_verified_at' => null],
        ] as $state) {
            DB::table('users')->where('id', $owner->id)->update($state);
            foreach ([
                fn () => $service->request($owner, $company->id, (string) Str::uuid(),
                    'expense', 100, 'PKR', null, 'Disallowed owner expense'),
                fn () => $service->decide($owner, $company->id, $pending, 1,
                    'approved', 'Disallowed decision'),
                fn () => $service->activeDelegations($owner, $company->id),
            ] as $action) {
                try {
                    $action();
                    $this->fail('Revoked account completed an approval operation.');
                } catch (AuthorizationException) {
                    $this->assertTrue(true);
                }
            }
        }

        $this->assertSame(1, DB::table('approval_requests')->count());
        $this->assertSame('pending', DB::table('approval_requests')->where('id', $pending)->value('status'));
        $this->assertSame(0, DB::table('approval_decisions')->count());
    }

    public function test_manager_requests_owner_decides_once_and_no_business_money_changes(): void
    {
        [$company, , , $people, $booking] = $this->fixture();
        $manager = $people['manager'][0];
        $owner = $people['owner'][0];
        $this->be($manager);
        $id = (string) Str::uuid();
        $service = app(ApprovalWorkflowService::class);
        $first = $service->request($manager, $company->id, $id, 'discount',
            15000, 'PKR', $booking, 'Special event discount review');
        $retry = $service->request($manager, $company->id, $id, 'discount',
            15000, 'PKR', $booking, 'Special event discount review');
        $this->assertSame('pending', $first['status']);
        $this->assertSame('pending', $retry['status']);
        $this->assertSame(1, DB::table('approval_requests')->count());
        $this->assertSame(1, DB::table('approval_alert_outbox')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'approval.requested')->count());

        $this->be($owner);
        $decision = $service->decide($owner, $company->id, $id, 1,
            'approved', 'Reasoned internal authorization only');
        $this->assertSame(['id' => $id, 'status' => 'approved', 'revision' => 2], $decision);
        $this->assertSame(1, DB::table('approval_decisions')->count());
        $this->assertSame(2, DB::table('approval_alert_outbox')->count());
        $this->assertSame('under_review', DB::table('booking_drafts')->where('id', $booking)->value('status'));
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(2, DB::table('approval_requests')->where('id', $id)->value('revision'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'approval.approved')->count());

        $this->expectException(ConflictHttpException::class);
        $service->decide($owner, $company->id, $id, 1, 'rejected', 'Duplicate conflicting decision');
    }

    public function test_exact_same_owner_decision_retry_is_read_only_and_mismatched_retry_rejected(): void
    {
        [$company, , , $people, $booking] = $this->fixture();
        $service = app(ApprovalWorkflowService::class);
        $this->be($people['manager'][0]);
        $request = (string) Str::uuid();
        $service->request($people['manager'][0], $company->id, $request, 'discount',
            300, 'PKR', $booking, 'Controlled synthetic discount proposal');
        $this->be($people['owner'][0]);
        $first = $service->decide($people['owner'][0], $company->id,
            $request, 1, 'approved', 'Approved private proposal without posting');
        $again = $service->decide($people['owner'][0], $company->id,
            $request, 1, 'approved', 'Approved private proposal without posting');
        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('approval_decisions')->where('approval_id', $request)->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('entity_id', $request)->where('action', 'approval.approved')->count());
        $this->assertSame(2, DB::table('approval_alert_outbox')->where('approval_id', $request)->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        try {
            $service->decide($people['owner'][0], $company->id,
                $request, 1, 'approved', 'Modified owner decision reason');
            $this->fail('Different decision reason replay accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        try {
            $service->decide($people['owner'][0], $company->id,
                $request, 1, 'rejected', 'Approved private proposal without posting');
            $this->fail('Conflicting reject replay accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->be($people['admin'][0]);
        $this->expectException(ConflictHttpException::class);
        $service->decide($people['admin'][0], $company->id,
            $request, 1, 'approved', 'Approved private proposal without posting');
    }

    public function test_self_approval_and_request_key_payload_reuse_are_blocked(): void
    {
        [$company, , , $people] = $this->fixture();
        $owner = $people['owner'][0];
        $service = app(ApprovalWorkflowService::class);
        $id = (string) Str::uuid();
        $service->request($owner, $company->id, $id, 'expense', 500, 'PKR', null, 'Proposed expense only');
        try {
            $service->decide($owner, $company->id, $id, 1, 'approved', 'Wrong self decision');
            $this->fail('Owner self-decision succeeded.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->expectException(ConflictHttpException::class);
        $service->request($owner, $company->id, $id, 'expense', 600, 'PKR', null, 'Different amount');
    }

    public function test_emergency_grant_checks_role_category_ceiling_and_owner_membership(): void
    {
        [$company, , , $people, $booking] = $this->fixture();
        $owner = $people['owner'][0];
        $admin = $people['admin'][0];
        $manager = $people['manager'][0];
        $service = app(ApprovalWorkflowService::class);

        $this->be($manager);
        $allowed = (string) Str::uuid();
        $tooLarge = (string) Str::uuid();
        $wrongType = (string) Str::uuid();
        $service->request($manager, $company->id, $allowed, 'discount', 50, 'PKR', $booking, 'Discount request');
        $service->request($manager, $company->id, $tooLarge, 'discount', 1001, 'PKR', $booking, 'Higher discount');
        $service->request($manager, $company->id, $wrongType, 'refund', 25, 'PKR', $booking, 'Refund authority');

        $this->be($owner);
        $grant = (string) Str::uuid();
        $service->grant($owner, $company->id, $grant, $admin->id, ['discount'], 1000,
            now()->addMinute()->toIso8601String(), now()->addHours(2)->toIso8601String(),
            'Short lived emergency permission');
        $this->be($admin);
        try {
            $service->decide($admin, $company->id, $tooLarge, 1, 'approved', 'Over ceiling');
            $this->fail('Over-ceiling approval succeeded.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        try {
            $service->decide($admin, $company->id, $wrongType, 1, 'approved', 'Wrong category');
            $this->fail('Wrong category approval succeeded.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        try {
            $service->decide($admin, $company->id, $allowed, 1, 'approved', 'Grant starts in future');
            $this->fail('Not-yet-active grant succeeded.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        Carbon::setTestNow(now()->addMinutes(2));
        try {
            $service->decide($admin, $company->id, $allowed, 1, 'approved', 'Permitted within limit');
            $this->assertSame($grant, DB::table('approval_decisions')
                ->where('approval_id', $allowed)->value('delegation_id'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_immediate_revocation_prevents_delegated_decisions(): void
    {
        [$company, , , $people, $booking] = $this->fixture();
        $owner = $people['owner'][0];
        $admin = $people['admin'][0];
        $manager = $people['manager'][0];
        $service = app(ApprovalWorkflowService::class);
        $this->be($manager);
        $request = (string) Str::uuid();
        $service->request($manager, $company->id, $request, 'discount', 50, 'PKR', $booking, 'Requested exception');
        $this->be($owner);
        $grant = (string) Str::uuid();
        $service->grant($owner, $company->id, $grant, $admin->id, ['discount'], 200,
            now()->toIso8601String(), now()->addHour()->toIso8601String(),
            'Emergency administrator');
        $service->revoke($owner, $company->id, $grant, 'Owner restored authority');
        $this->assertSame(2, DB::table('approval_delegation_events')->where('delegation_id', $grant)->count());

        $this->be($admin);
        $this->expectException(AuthorizationException::class);
        $service->decide($admin, $company->id, $request, 1, 'approved', 'Revoked grant attempt');
    }

    public function test_revoked_membership_foreign_tenant_and_manager_without_assignment_denied(): void
    {
        [$company, $foreign, , $people, $booking] = $this->fixture();
        $manager = $people['manager'][0];
        $service = app(ApprovalWorkflowService::class);
        $this->be($manager);
        try {
            $service->request($manager, $foreign->id, (string) Str::uuid(),
                'discount', 50, 'PKR', $booking, 'Wrong tenant');
            $this->fail('Foreign company request accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        DB::table('hall_assignments')->where('membership_id', $people['manager'][1]->id)->delete();
        try {
            $service->request($manager, $company->id, (string) Str::uuid(),
                'discount', 50, 'PKR', $booking, 'No hall assignment');
            $this->fail('Unassigned manager request accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $people['manager'][1]->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $service->inbox($manager, $company->id);
    }

    public function test_inbox_filters_manager_requests_and_owner_can_see_all(): void
    {
        [$company, , , $people] = $this->fixture();
        $service = app(ApprovalWorkflowService::class);
        $owner = $people['owner'][0];
        $admin = $people['admin'][0];
        $service->request($owner, $company->id, (string) Str::uuid(),
            'expense', 500, 'PKR', null, 'Proposed office expense');
        $this->be($admin);
        $service->request($admin, $company->id, (string) Str::uuid(),
            'expense', 600, 'PKR', null, 'Proposed supplies expense');
        $this->assertCount(2, $service->inbox($admin, $company->id));
        $manager = $people['manager'][0];
        $this->be($manager);
        $this->assertCount(0, $service->inbox($manager, $company->id));
    }

    public function test_illegal_amount_category_contact_reason_and_delegation_grant_are_rejected(): void
    {
        [$company, , , $people] = $this->fixture();
        $service = app(ApprovalWorkflowService::class);
        $owner = $people['owner'][0];
        foreach ([
            ['expense', 0, 'Reason'], ['expense', 20, 'guest@example.test'],
            ['invalid', 20, 'Reason'], ['expense', 20, '<script>'],
        ] as [$category, $amount, $reason]) {
            try {
                $service->request($owner, $company->id, (string) Str::uuid(), $category,
                    $amount, 'PKR', null, $reason);
                $this->fail('Invalid request accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $service->grant($owner, $company->id, (string) Str::uuid(), $owner->id,
            ['discount'], 500, now()->toIso8601String(), now()->addDays(8)->toIso8601String(),
            'Invalid self grant');
    }

    public function test_audit_insert_failure_rolls_back_decision_outbox_and_status(): void
    {
        [$company, , , $people, $booking] = $this->fixture();
        $service = app(ApprovalWorkflowService::class);
        $this->be($people['manager'][0]);
        $id = (string) Str::uuid();
        $service->request($people['manager'][0], $company->id, $id,
            'discount', 150, 'PKR', $booking, 'Request for test');
        $this->be($people['owner'][0]);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_approval_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.action = 'approval.approved' THEN
        RAISE EXCEPTION 'Injected approval audit failure';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_approval_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_approval_audit_test()');
        try {
            $service->decide($people['owner'][0], $company->id, $id, 1,
                'approved', 'Should atomically roll back');
            $this->fail('Audit failure was ignored.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected approval audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_approval_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_approval_audit_test()');
        }
        $this->assertSame('pending', DB::table('approval_requests')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame(1, DB::table('approval_alert_outbox')->where('approval_id', $id)->count());
    }

    public function test_decisions_and_alerts_are_immutable_postgres_history(): void
    {
        [$company, , , $people, $booking] = $this->fixture();
        $service = app(ApprovalWorkflowService::class);
        $this->be($people['manager'][0]);
        $id = (string) Str::uuid();
        $service->request($people['manager'][0], $company->id, $id,
            'discount', 150, 'PKR', $booking, 'Proposed exception');
        $this->be($people['owner'][0]);
        $service->decide($people['owner'][0], $company->id, $id, 1, 'rejected', 'Does not meet rules');
        foreach (['approval_decisions', 'approval_alert_outbox'] as $table) {
            try {
                DB::transaction(static fn () => DB::table($table)->delete());
                $this->fail('Protected history was deleted: '.$table);
            } catch (QueryException $error) {
                $this->assertStringContainsString('history entries cannot be updated or deleted', $error->getMessage());
            }
        }
        $this->assertSame(1, DB::table('approval_decisions')->count());
    }
}
