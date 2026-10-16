<?php

namespace Tests\Feature;

use App\Filament\Pages\OperationsPlanning;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\BookingDraftService;
use App\Services\CustomerProfileService;
use App\Services\HallProgramRulesService;
use App\Services\OperationsPlanningService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class OperationsPlanningTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Operations A', 'slug' => 'operations-a']);
        $other = Company::create(['name' => 'Operations B', 'slug' => 'operations-b']);
        $hall = Hall::create(['company_id' => $company->id,
            'name' => 'Test Setup Hall', 'slug' => 'setup', 'capacity' => 250]);
        $otherHall = Hall::create(['company_id' => $company->id,
            'name' => 'Unassigned Hall', 'slug' => 'unassigned', 'capacity' => 250]);
        $actors = [];
        foreach (['owner', 'admin', 'manager', 'operations', 'reception'] as $role) {
            $user = User::create(['name' => ucfirst($role),
                'email' => 'ops-'.$role.'@example.test', 'password' => 'synthetic']);
            $user->markEmailAsVerified();
            $membership = CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $user->id, 'role' => $role,
            ]);
            $actors[$role] = [$user, $membership];
        }
        foreach (['manager', 'operations'] as $role) {
            HallAssignment::create(['company_id' => $company->id,
                'hall_id' => $hall->id, 'membership_id' => $actors[$role][1]->id]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($actors['owner'][0]);
        $program = static fn (string $s, string $e): array => [
            'start' => $s, 'end' => $e, 'setupMinutes' => 10, 'cleaningMinutes' => 10,
            'graceMinutes' => 10, 'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 0, 'rentalRateMinor' => 0,
        ];
        app(HallProgramRulesService::class)->configure($actors['owner'][0],
            $company->id, $hall->id, [
                'morning' => $program('08:00', '11:00'),
                'noon' => $program('12:00', '15:00'),
                'evening' => $program('17:00', '22:00'),
            ], 'PKR', 0, 'Synthetic operational planning rules');
        $customer = (string) Str::uuid();
        app(CustomerProfileService::class)->create($actors['owner'][0],
            $company->id, $customer, 'Fixture Client',
            'synthetic-ops@example.test', null, 'Synthetic only');
        $booking = (string) Str::uuid();
        app(BookingDraftService::class)->create($actors['owner'][0],
            $company->id, $booking, $hall->id, $customer,
            '2027-03-15', 'evening', 100, 'Unconfirmed planning proposal');
        return [$company, $other, $hall, $otherHall, $actors, $booking];
    }

    public function test_internal_task_create_is_idempotent_and_review_is_atomic_audited_and_revision_guarded(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $id = (string) Str::uuid();
        $result = $service->create($actors['owner'][0], $company->id, $id,
            $booking, 'Prepare staff setup checklist', 'Internal proposal planning only');
        $this->assertSame('planned', $result['status']);
        $this->assertSame($result, $service->create($actors['owner'][0], $company->id,
            $id, $booking, 'Prepare staff setup checklist', 'Internal proposal planning only'));
        $this->assertSame(1, DB::table('operations_plan_tasks')->count());
        $this->assertSame(1, DB::table('operations_plan_events')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());

        $review = $service->setReviewed($actors['owner'][0], $company->id,
            $id, 1, true, 'Checked internal proposal setup plan');
        $this->assertSame('reviewed', $review['status']);
        $this->assertSame(2, $review['revision']);
        $this->assertSame(2, DB::table('operations_plan_events')->count());
        $this->assertSame(2, DB::table('audit_events')->where('entity_id', $id)->count());
        try {
            $service->setReviewed($actors['owner'][0], $company->id,
                $id, 1, false, 'Stale plan edit rejected');
            $this->fail('Stale revision changed task status.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $reopened = $service->setReviewed($actors['owner'][0], $company->id,
            $id, 2, false, 'Reopened internal task review');
        $this->assertSame('planned', $reopened['status']);
        $this->assertSame(3, $reopened['revision']);
        $this->assertSame(3, DB::table('operations_plan_events')->count());
    }

    public function test_manager_and_operations_staff_restricted_to_assigned_hall_and_reception_denied(): void
    {
        [$company, $other, $hall, $unassigned, $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $task = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $task,
            $booking, 'Plan approved chairs layout', 'Only for internal proposal');
        foreach (['manager', 'operations'] as $role) {
            $this->be($actors[$role][0]);
            $visible = $service->tasks($actors[$role][0], $company->id);
            $this->assertCount(1, $visible);
            $this->assertSame($task, $visible[0]['id']);
        }
        $this->be($actors['reception'][0]);
        try {
            $service->tasks($actors['reception'][0], $company->id);
            $this->fail('Reception viewed restricted planning history.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($actors['manager'][0]);
        $this->expectException(AuthorizationException::class);
        $service->tasks($actors['manager'][0], $other->id);
    }

    public function test_withdrawn_proposal_blocks_further_task_progression(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $task = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $task,
            $booking, 'Draft stage assembly plan', 'Only internal planning');
        app(\App\Services\BookingDraftReviewService::class)->withdraw(
            $actors['owner'][0], $company->id, $booking, 1,
            'Internal proposal withdrawn without a booking'
        );
        $this->expectException(ConflictHttpException::class);
        $service->setReviewed($actors['owner'][0], $company->id,
            $task, 1, true, 'Forbidden review after withdrawal');
    }

    public function test_review_rejects_stale_revision_even_after_reopening(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $id = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $id,
            $booking, 'Stage safety planning note', 'Initial internal planning');
        $service->setReviewed($actors['owner'][0], $company->id, $id,
            1, true, 'Private setup plan reviewed');
        $service->setReviewed($actors['owner'][0], $company->id, $id,
            2, false, 'Planning review reopened');
        $this->expectException(ConflictHttpException::class);
        $service->setReviewed($actors['owner'][0], $company->id, $id,
            1, true, 'Replay stale previous revision');
    }

    public function test_audit_rejection_rolls_back_task_and_planning_event(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_ops_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $tag$
BEGIN
    IF NEW.action = 'operations.plan_created' THEN
        RAISE EXCEPTION 'Injected operations audit failure';
    END IF;
    RETURN NEW;
END;
$tag$;
SQL);
        DB::statement('CREATE TRIGGER fail_ops_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_ops_audit_test()');
        try {
            app(OperationsPlanningService::class)->create(
                $actors['owner'][0], $company->id, (string) Str::uuid(),
                $booking, 'Audit guarded setup plan', 'Synthetic rollback reason'
            );
            $this->fail('Task committed after audit failure.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected operations audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_ops_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_ops_audit_test()');
        }
        $this->assertSame(0, DB::table('operations_plan_tasks')->count());
        $this->assertSame(0, DB::table('operations_plan_events')->count());
    }

    public function test_direct_postgresql_task_cannot_mismatch_booking_and_hall(): void
    {
        [$company, , , $unassigned, $actors, $booking] = $this->fixture();
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($company, $unassigned, $actors, $booking): void {
            DB::table('operations_plan_tasks')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company->id,
                'booking_id' => $booking, 'hall_id' => $unassigned->id,
                'title' => 'Wrong hall planning', 'status' => 'planned',
                'revision' => 1, 'creation_reason' => 'Wrong hall forbidden',
                'created_by' => $actors['owner'][0]->id, 'updated_by' => $actors['owner'][0]->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function test_postgresql_rejects_silent_planning_task_rewrites_deletes_and_skipped_revisions(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $taskId = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $taskId, $booking,
            'Verify safe stage preparation', 'Synthetic no-contact task plan');

        foreach ([
            ['title' => 'Silently replaced planning title'],
            ['status' => 'reviewed', 'revision' => 5],
            ['status' => 'reviewed'],
        ] as $rewrite) {
            try {
                DB::transaction(fn () => DB::table('operations_plan_tasks')
                    ->where('id', $taskId)->update($rewrite));
                $this->fail('Direct PostgreSQL task rewrite was accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('VenueDesk planning task', $exception->getMessage());
            }
        }
        try {
            DB::transaction(fn () => DB::table('operations_plan_tasks')
                ->where('id', $taskId)->delete());
            $this->fail('Direct PostgreSQL task deletion was accepted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('cannot be hard deleted', $exception->getMessage());
        }
        $this->assertSame(1, (int) DB::table('operations_plan_tasks')
            ->where('id', $taskId)->value('revision'));
        $this->assertSame(1, DB::table('operations_plan_events')->where('task_id', $taskId)->count());
        $result = $service->setReviewed($actors['owner'][0], $company->id,
            $taskId, 1, true, 'Legitimate approved review transition');
        $this->assertSame('reviewed', $result['status']);
        $this->assertSame(2, $result['revision']);
        $this->assertSame(2, DB::table('operations_plan_events')->where('task_id', $taskId)->count());
    }

    public function test_revoked_hall_assignment_blocks_planning_review_and_prior_livewire_session(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $id = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $id,
            $booking, 'Recheck stage lighting permissions', 'Internal assigned hall planning');
        $this->be($actors['manager'][0]);
        $this->assertCount(1, $service->tasks($actors['manager'][0], $company->id));
        DB::table('hall_assignments')->where('membership_id', $actors['manager'][1]->id)->delete();
        $this->assertSame([], $service->tasks($actors['manager'][0], $company->id));
        try {
            $service->setReviewed($actors['manager'][0], $company->id,
                $id, 1, true, 'Former assignment is no longer valid');
            $this->fail('Revoked manager assignment reviewed a planning task.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($actors['owner'][0]);
        $mounted = Livewire::test(OperationsPlanning::class);
        $actors['owner'][1]->update(['is_active' => false]);
        $mounted->call('refresh')->assertForbidden();
        $this->assertSame(1, DB::table('operations_plan_events')->where('task_id', $id)->count());
    }

    public function test_failed_review_audit_write_rolls_back_task_revision_and_event(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $id = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $id,
            $booking, 'Check pre-event lighting layout', 'Synthetic operational planning only');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_ops_review_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $ops_review$
BEGIN
    IF NEW.action = 'operations.plan_reviewed' THEN
        RAISE EXCEPTION 'Injected operations review audit failure';
    END IF;
    RETURN NEW;
END;
$ops_review$;
SQL);
        DB::statement('CREATE TRIGGER fail_ops_review_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_ops_review_audit_test()');
        try {
            $service->setReviewed($actors['owner'][0], $company->id,
                $id, 1, true, 'Review must not survive audit failure');
            $this->fail('Task review succeeded despite failed required audit.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected operations review audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_ops_review_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_ops_review_audit_test()');
        }
        $saved = DB::table('operations_plan_tasks')->where('id', $id)->first();
        $this->assertSame('planned', $saved->status);
        $this->assertSame(1, (int) $saved->revision);
        $this->assertSame(1, DB::table('operations_plan_events')->where('task_id', $id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('entity_id', $id)->count());
    }

    public function test_server_side_status_filter_remains_tenant_and_role_scoped(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $reviewed = (string) Str::uuid();
        $planned = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $reviewed,
            $booking, 'Check reviewed stage checklist', 'First planning record');
        $service->create($actors['owner'][0], $company->id, $planned,
            $booking, 'Check pending lighting checklist', 'Second planning record');
        $service->setReviewed($actors['owner'][0], $company->id,
            $reviewed, 1, true, 'Owner reviewed one checklist');
        $this->assertSame([$planned], array_column(
            $service->tasks($actors['owner'][0], $company->id, 30, 'planned'), 'id'
        ));
        $this->assertSame([$reviewed], array_column(
            $service->tasks($actors['owner'][0], $company->id, 30, 'reviewed'), 'id'
        ));
        Livewire::test(OperationsPlanning::class)
            ->assertSee('Check reviewed stage checklist')
            ->assertSee('Check pending lighting checklist')
            ->set('statusFilter', 'reviewed')
            ->assertSee('Check reviewed stage checklist')
            ->assertDontSee('Check pending lighting checklist');
        $this->expectException(InvalidArgumentException::class);
        $service->tasks($actors['owner'][0], $company->id, 30, 'forged');
    }

    public function test_planning_blocks_invalid_capacity_and_inactive_customer_before_mutation(): void
    {
        [$company, , $hall, , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        DB::table('halls')->where('id', $hall->id)->update(['capacity' => 50]);
        try {
            $service->create($actors['owner'][0], $company->id, (string) Str::uuid(),
                $booking, 'Unsafe overloaded setup plan', 'Capacity has become invalid');
            $this->fail('Invalid current hall capacity allowed planning.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, DB::table('operations_plan_tasks')->count());

        DB::table('halls')->where('id', $hall->id)->update(['capacity' => 250]);
        $id = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $id,
            $booking, 'Internal stage layout draft', 'Valid test-only setup planning');
        $customer = DB::table('booking_drafts')->where('id', $booking)->value('customer_id');
        DB::table('customer_profiles')->where('id', $customer)->update(['is_active' => false]);
        try {
            $service->setReviewed($actors['owner'][0], $company->id,
                $id, 1, true, 'Invalid inactive customer cannot progress');
            $this->fail('Inactive customer proposal advanced its planning review.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, (int) DB::table('operations_plan_tasks')
            ->where('id', $id)->value('revision'));
        $this->assertSame(1, DB::table('operations_plan_events')->where('task_id', $id)->count());
    }

    public function test_immutable_history_is_visible_only_to_current_assigned_staff(): void
    {
        [$company, $foreign, , , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $id = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $id, $booking,
            'Internal equipment layout task', 'Initial planning note');
        $service->setReviewed($actors['owner'][0], $company->id, $id, 1,
            true, 'Approved internal setup review');
        $events = $service->history($actors['owner'][0], $company->id, $id);
        $this->assertSame([2, 1], array_column($events, 'revision'));
        $this->assertSame('Approved internal setup review', $events[0]['reason']);
        Livewire::test(OperationsPlanning::class)
            ->call('showHistory', $id)->assertSee('Planning review history')
            ->assertSee('Approved internal setup review');
        $this->be($actors['manager'][0]);
        $this->assertCount(2, $service->history($actors['manager'][0], $company->id, $id));
        DB::table('hall_assignments')->where('membership_id', $actors['manager'][1]->id)->delete();
        try {
            $service->history($actors['manager'][0], $company->id, $id);
            $this->fail('Revoked hall staff read private planning history.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($actors['owner'][0]);
        $this->expectException(AuthorizationException::class);
        $service->history($actors['owner'][0], $foreign->id, $id);
    }

    public function test_livewire_rejects_invalid_status_filter_without_querying_tasks(): void
    {
        $this->fixture();
        Livewire::test(OperationsPlanning::class)
            ->set('statusFilter', 'forged-status')
            ->assertHasErrors(['statusFilter']);
    }

    public function test_planning_company_deep_link_allows_only_current_member_companies(): void
    {
        [$company, $other, , , $actors, $booking] = $this->fixture();
        $owner = $actors['owner'][0];
        $task = (string) Str::uuid();
        app(OperationsPlanningService::class)->create($owner, $company->id, $task,
            $booking, 'Private approved hall lighting plan', 'Search navigation test');
        CompanyMembership::create([
            'company_id' => $other->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        Livewire::withQueryParams(['company' => $other->id])
            ->test(OperationsPlanning::class)->assertSet('companyId', $other->id)
            ->assertDontSee('Private approved hall lighting plan');
        Livewire::withQueryParams(['company' => $company->id])
            ->test(OperationsPlanning::class)->assertSet('companyId', $company->id)
            ->assertSee('Private approved hall lighting plan');

        $foreign = Company::create(['name' => 'Forbidden Planning Company', 'slug' => 'forbidden-ops-deeplink']);
        Livewire::withQueryParams(['company' => $foreign->id])
            ->test(OperationsPlanning::class)->assertForbidden();
    }

    public function test_filament_planning_page_follows_role_and_company_scoping(): void
    {
        [$company, $other, , , $actors, $booking] = $this->fixture();
        Livewire::test(OperationsPlanning::class)
            ->assertSee('Internal planning checklist only')
            ->set('bookingId', $booking)
            ->set('taskTitle', 'Review stage lighting plan')
            ->set('reason', 'For internal setup planning')
            ->call('createTask')->assertHasNoErrors()
            ->assertSee('Review stage lighting plan');
        $this->assertSame(1, DB::table('operations_plan_tasks')->where('company_id', $company->id)->count());

        $this->be($actors['manager'][0]);
        Livewire::test(OperationsPlanning::class)->assertSee('Review stage lighting plan')
            ->set('companyId', $other->id)->assertForbidden();
        $this->be($actors['reception'][0]);
        Livewire::test(OperationsPlanning::class)->assertForbidden();
    }
}
