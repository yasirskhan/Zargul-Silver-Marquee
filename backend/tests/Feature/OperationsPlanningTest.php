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
LANGUAGE plpgsql AS $
BEGIN
    IF NEW.action = 'operations.plan_created' THEN
        RAISE EXCEPTION 'Injected operations audit failure';
    END IF;
    RETURN NEW;
END;
$;
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
