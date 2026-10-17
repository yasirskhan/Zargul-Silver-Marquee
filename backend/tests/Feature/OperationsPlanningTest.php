<?php

namespace Tests\Feature;

use App\Filament\Pages\OperationsPlanning;
use App\Filament\Pages\BookingProposals;
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



    public function test_owner_can_open_exact_booking_directly_in_planning_without_creating_claim(): void
    {
        [$company, $other, , , , $booking] = $this->fixture();
        Livewire::test(BookingProposals::class)
            ->assertSee('Plan setup');
        $page = Livewire::withQueryParams(['company' => $company->id, 'proposal' => $booking])
            ->test(OperationsPlanning::class)
            ->assertSet('bookingId', $booking)
            ->assertSee('Save nonbinding planning task');
        $this->assertSame('Test Setup Hall | 2027-03-15 Evening',
            $page->instance()->proposalOptions()[$booking]);

        // A forged or inaccessible UUID is never accepted as a selected task source.
        Livewire::withQueryParams(['company' => $company->id, 'proposal' => (string) Str::uuid()])
            ->test(OperationsPlanning::class)->assertForbidden();
        Livewire::withQueryParams(['company' => $other->id, 'proposal' => $booking])
            ->test(OperationsPlanning::class)->assertForbidden();
        $this->assertSame(0, DB::table('operations_plan_tasks')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_exact_authorized_older_proposal_outside_dropdown_limit_is_still_selectable(): void
    {
        [$company, , $hall, , $actors, $booking] = $this->fixture();
        $existing = DB::table('booking_drafts')->where('id', $booking)->first();
        for ($i = 0; $i < 50; $i++) {
            DB::table('booking_drafts')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company->id,
                'hall_id' => $hall->id, 'customer_id' => $existing->customer_id,
                'event_date' => (new \DateTimeImmutable('2027-01-01'))
                    ->modify('+'.$i.' days')->format('Y-m-d'),
                'program' => 'evening', 'requested_guests' => 80,
                'status' => 'draft', 'created_by' => $actors['owner'][0]->id,
                'revision' => 1,
            ]);
        }

        $component = Livewire::withQueryParams([
            'company' => $company->id, 'proposal' => $booking,
        ])->test(OperationsPlanning::class)->assertSet('bookingId', $booking);
        $this->assertArrayHasKey($booking, $component->instance()->proposalOptions());
        $this->assertSame(0, DB::table('operations_plan_tasks')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }


    public function test_manager_proposal_deep_link_rejects_unassigned_hall_record(): void
    {
        [$company, , , $unassigned, $actors, $booking] = $this->fixture();
        $saved = DB::table('booking_drafts')->where('id', $booking)->first();
        $private = (string) Str::uuid();

        // Synthetic cross-hall record to challenge the screen's scope gate.
        DB::table('booking_drafts')->insert([
            'id' => $private, 'company_id' => $company->id,
            'hall_id' => $unassigned->id, 'customer_id' => $saved->customer_id,
            'event_date' => '2027-03-18', 'program' => 'noon',
            'requested_guests' => 100, 'status' => 'draft',
            'created_by' => $actors['owner'][0]->id, 'revision' => 1,
        ]);

        $this->be($actors['manager'][0]);
        Livewire::withQueryParams(['company' => $company->id, 'proposal' => $booking])
            ->test(OperationsPlanning::class)->assertSet('bookingId', $booking);
        Livewire::withQueryParams(['company' => $company->id, 'proposal' => $private])
            ->test(OperationsPlanning::class)->assertForbidden();

        $this->assertSame(0, DB::table('operations_plan_tasks')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }


    public function test_proposal_deep_link_filters_planning_tasks_and_show_all_recovers_company_list(): void
    {
        [$company, , $hall, , $actors, $booking] = $this->fixture();
        $owner = $actors['owner'][0];
        $original = DB::table('booking_drafts')->where('id', $booking)->first();
        $second = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $owner, $company->id, $second, $hall->id, $original->customer_id,
            '2027-03-16', 'morning', 80, 'Separate unconfirmed internal proposal'
        );
        $service = app(OperationsPlanningService::class);
        $firstTask = (string) Str::uuid();
        $secondTask = (string) Str::uuid();
        $service->create($owner, $company->id, $firstTask, $booking,
            'Set internal evening chairs', 'Only for original proposal');
        $service->create($owner, $company->id, $secondTask, $second,
            'Set internal morning tables', 'Only for second proposal');

        $panel = Livewire::withQueryParams([
            'company' => $company->id, 'proposal' => $booking,
        ])->test(OperationsPlanning::class)
            ->assertSet('bookingId', $booking)
            ->assertSee('Set internal evening chairs')
            ->assertDontSee('Set internal morning tables')
            ->assertSee('Showing tasks for proposal')
            ->set('statusFilter', 'planned')
            ->call('showAllTasks')
            ->assertSet('bookingId', '')
            ->assertSet('statusFilter', 'all')
            ->assertSee('Set internal evening chairs')
            ->assertSee('Set internal morning tables');

        $this->assertCount(2, $panel->get('tasks'));
        $filtered = $service->tasks($owner, $company->id, 30, 'all', $booking);
        $this->assertSame([$firstTask], array_column($filtered, 'id'));
        $this->assertSame(2, DB::table('operations_plan_tasks')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_changing_selected_proposal_filters_tasks_immediately_and_clears_unsent_notes(): void
    {
        [$company, , $hall, , $actors, $booking] = $this->fixture();
        $owner = $actors['owner'][0];
        $saved = DB::table('booking_drafts')->where('id', $booking)->first();
        $second = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $owner, $company->id, $second, $hall->id, $saved->customer_id,
            '2027-03-17', 'noon', 90, 'Alternative internal planning proposal'
        );
        $service = app(OperationsPlanningService::class);
        $service->create($owner, $company->id, (string) Str::uuid(), $booking,
            'Plan original evening stage', 'Keep original internal plan private');
        $service->create($owner, $company->id, (string) Str::uuid(), $second,
            'Plan alternative noon stage', 'Keep alternative internal plan private');

        $component = Livewire::test(OperationsPlanning::class)
            ->assertSee('Plan original evening stage')
            ->assertSee('Plan alternative noon stage')
            ->set('bookingId', $booking)
            ->assertSee('Plan original evening stage')
            ->assertDontSee('Plan alternative noon stage')
            ->set('taskTitle', 'Unsent original stage notes')
            ->set('reason', 'Original private setup rationale')
            ->set('reviewReason', 'Original private review rationale');
        $oldKey = $component->get('createRequestKey');

        $component->set('bookingId', $second)
            ->assertSet('bookingId', $second)
            ->assertSet('taskTitle', '')
            ->assertSet('reason', '')
            ->assertSet('reviewReason', '')
            ->assertSet('historyEvents', [])
            ->assertSee('Plan alternative noon stage')
            ->assertDontSee('Plan original evening stage');
        $this->assertNotSame($oldKey, $component->get('createRequestKey'));

        $component->set('bookingId', '')
            ->assertSee('Plan original evening stage')
            ->assertSee('Plan alternative noon stage')
            ->set('bookingId', (string) Str::uuid())->assertForbidden();

        $this->assertSame(2, DB::table('operations_plan_tasks')->count());
        $this->assertSame(2, DB::table('operations_plan_events')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_authorized_planning_tasks_show_hall_date_and_program_without_customer_contact(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $owner = $actors['owner'][0];
        $task = (string) Str::uuid();
        app(OperationsPlanningService::class)->create(
            $owner, $company->id, $task, $booking,
            'Plan check-in table placement', 'Synthetic hall event preparation'
        );

        $listed = app(OperationsPlanningService::class)->tasks($owner, $company->id);
        $this->assertCount(1, $listed);
        $this->assertSame($task, $listed[0]['id']);
        $this->assertSame('Test Setup Hall', $listed[0]['hallName']);
        $this->assertSame('2027-03-15', $listed[0]['eventDate']);
        $this->assertSame('evening', $listed[0]['program']);
        $this->assertArrayNotHasKey('customer_id', $listed[0]);
        $this->assertArrayNotHasKey('customerEmail', $listed[0]);

        Livewire::test(OperationsPlanning::class)
            ->assertSee('Hall: Test Setup Hall')
            ->assertSee('2027-03-15')
            ->assertSee('Evening')
            ->assertDontSee('synthetic-ops@example.test');
        $this->be($actors['manager'][0]);
        $managerTasks = app(OperationsPlanningService::class)
            ->tasks($actors['manager'][0], $company->id);
        $this->assertSame('Test Setup Hall', $managerTasks[0]['hallName']);
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_planning_pages_keep_all_current_company_tasks_reachable_with_bounded_queries(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $owner = $actors['owner'][0];
        $service = app(OperationsPlanningService::class);
        for ($i = 0; $i < 32; $i++) {
            $service->create($owner, $company->id, (string) Str::uuid(), $booking,
                'Synthetic setup item '.($i + 1), 'Company scoped pagination check');
        }

        $page = Livewire::test(OperationsPlanning::class)
            ->assertSet('taskPage', 1)
            ->assertSet('hasMoreTasks', true)
            ->assertSee('Next 30 tasks');
        $first = array_column($page->get('tasks'), 'id');
        $this->assertCount(30, $first);
        $page->call('nextTasksPage')
            ->assertSet('taskPage', 2)
            ->assertSet('hasMoreTasks', false)
            ->assertSee('Previous 30 tasks');
        $second = array_column($page->get('tasks'), 'id');
        $this->assertCount(2, $second);
        $this->assertCount(32, array_unique(array_merge($first, $second)));
        $page->call('nextTasksPage')->assertSet('taskPage', 2);
        $page->call('previousTasksPage')->assertSet('taskPage', 1)
            ->assertSet('hasMoreTasks', true);
        $page->set('statusFilter', 'reviewed')
            ->assertSet('taskPage', 1)
            ->assertSet('hasMoreTasks', false)
            ->assertSet('tasks', []);
        $this->expectException(AuthorizationException::class);
        $service->tasks($owner, $company->id, 30, 'all', null, 5001);
    }

    public function test_planning_hall_filter_follows_current_company_and_staff_assignments(): void
    {
        [$company, , $hall, $otherHall, $actors, $booking] = $this->fixture();
        $owner = $actors['owner'][0];
        $program = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end,
            'setupMinutes' => 10, 'cleaningMinutes' => 10,
            'graceMinutes' => 10, 'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 0, 'rentalRateMinor' => 0,
        ];
        app(HallProgramRulesService::class)->configure($owner, $company->id, $otherHall->id, [
            'morning' => $program('08:00', '11:00'),
            'noon' => $program('12:00', '15:00'),
            'evening' => $program('17:00', '22:00'),
        ], 'PKR', 0, 'Synthetic second hall program rules');
        $existing = DB::table('booking_drafts')->where('id', $booking)->first();
        $otherBooking = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $owner, $company->id, $otherBooking, $otherHall->id,
            $existing->customer_id, '2027-03-19', 'noon', 75, 'Other hall private proposal'
        );
        $service = app(OperationsPlanningService::class);
        $service->create($owner, $company->id, (string) Str::uuid(), $booking,
            'Plan original hall lights', 'Private original setup notes');
        $service->create($owner, $company->id, (string) Str::uuid(), $otherBooking,
            'Plan second hall stage', 'Private second hall notes');

        $panel = Livewire::test(OperationsPlanning::class)
            ->assertSee('Plan original hall lights')
            ->assertSee('Plan second hall stage')
            ->set('hallFilter', $hall->id)
            ->assertSet('hallFilter', $hall->id)
            ->assertSee('Plan original hall lights')
            ->assertDontSee('Plan second hall stage')
            ->set('hallFilter', $otherHall->id)
            ->assertSee('Plan second hall stage')
            ->assertDontSee('Plan original hall lights')
            ->call('showAllTasks')
            ->assertSet('hallFilter', '')
            ->assertSee('Plan original hall lights')
            ->assertSee('Plan second hall stage');
        $panel->set('hallFilter', (string) Str::uuid())->assertForbidden();

        $this->be($actors['manager'][0]);
        $manager = Livewire::test(OperationsPlanning::class);
        $this->assertArrayHasKey($hall->id, $manager->instance()->hallOptions());
        $this->assertArrayNotHasKey($otherHall->id, $manager->instance()->hallOptions());
        $manager->set('hallFilter', $otherHall->id)->assertForbidden();
        $this->assertSame(2, DB::table('operations_plan_tasks')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_task_title_search_is_literal_case_insensitive_scoped_and_cleared_by_show_all(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $owner = $actors['owner'][0];
        $service = app(OperationsPlanningService::class);
        $service->create($owner, $company->id, (string) Str::uuid(), $booking,
            'Plan evening stage lights', 'Private stage preparation rationale');
        $service->create($owner, $company->id, (string) Str::uuid(), $booking,
            'Arrange chairs for hall', 'Private chair preparation rationale');

        $panel = Livewire::test(OperationsPlanning::class)
            ->set('taskSearch', 'STAGE')
            ->assertSet('taskSearch', 'STAGE')
            ->assertSee('Plan evening stage lights')
            ->assertDontSee('Arrange chairs for hall')
            ->set('taskSearch', '%')
            ->assertSet('tasks', [])
            ->set('taskSearch', '')
            ->assertSee('Plan evening stage lights')
            ->assertSee('Arrange chairs for hall')
            ->set('taskSearch', 'stage')
            ->call('showAllTasks')
            ->assertSet('taskSearch', '')
            ->assertSee('Arrange chairs for hall');
        $this->assertSame(2, DB::table('operations_plan_tasks')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->expectException(InvalidArgumentException::class);
        $service->tasks($owner, $company->id, 30, 'all', null, 0, null,
            str_repeat('x', 101));
    }

    public function test_planning_status_filter_is_validated_for_company_scoped_links(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $service = app(OperationsPlanningService::class);
        $id = (string) Str::uuid();
        $service->create($actors['owner'][0], $company->id, $id, $booking,
            'Plan internal stage support', 'Synthetic preparation for private venue');

        Livewire::test(OperationsPlanning::class)
            ->set('statusFilter', 'planned')->call('refresh')
            ->assertHasNoErrors()
            ->assertSet('statusFilter', 'planned')
            ->assertSee('Plan internal stage support')
            ->set('statusFilter', 'forged-status')->call('refresh')
            ->assertHasErrors(['statusFilter']);
        $this->assertSame('planned', DB::table('operations_plan_tasks')
            ->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }

    public function test_company_switch_clears_unsent_planning_notes_and_regenerates_request_key(): void
    {
        [$company, $other, , , $actors, $booking] = $this->fixture();
        CompanyMembership::create([
            'company_id' => $other->id, 'user_id' => $actors['owner'][0]->id,
            'role' => 'owner',
        ]);
        $component = Livewire::test(OperationsPlanning::class)
            ->set('bookingId', $booking)
            ->set('taskTitle', 'Unsent private Company A setup')
            ->set('reason', 'Company A operational notes')
            ->set('reviewReason', 'Company A review rationale')
            ->set('statusFilter', 'planned');
        $oldKey = $component->get('createRequestKey');

        $component->set('companyId', $other->id)
            ->assertSet('loadedCompanyId', $other->id)
            ->assertSet('bookingId', '')
            ->assertSet('taskTitle', '')
            ->assertSet('reason', '')
            ->assertSet('reviewReason', '')
            ->assertSet('statusFilter', 'all')
            ->assertSet('historyTaskId', null)
            ->assertSet('historyEvents', [])
            ->assertSet('tasks', []);
        $this->assertTrue(Str::isUuid($component->get('createRequestKey')));
        $this->assertNotSame($oldKey, $component->get('createRequestKey'));
        $this->assertSame(0, DB::table('operations_plan_tasks')->count());
        $this->assertSame(0, DB::table('operations_plan_events')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }


    public function test_direct_planning_service_denies_cached_revoke_and_email_verification_reset(): void
    {
        [$company, , , , $actors, $booking] = $this->fixture();
        $owner = $actors['owner'][0];
        $service = app(OperationsPlanningService::class);
        $task = (string) Str::uuid();
        $service->create($owner, $company->id, $task, $booking,
            'Synthetic internal setup plan', 'Owner saved an internal task');
        $this->assertSame(1, DB::table('operations_plan_events')->count());

        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active);
        try {
            $service->tasks($owner, $company->id);
            $this->fail('Cached suspended owner read restricted planning tasks.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        try {
            $service->setReviewed($owner, $company->id, $task, 1, true,
                'Revoked owner must not review');
            $this->fail('Cached suspended owner reviewed a planning task.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        DB::table('users')->where('id', $owner->id)->update([
            'is_active' => true, 'email_verified_at' => null,
        ]);
        $this->assertTrue($owner->hasVerifiedEmail());
        try {
            $service->tasks($owner, $company->id);
            $this->fail('Cached verification survived a persisted revocation.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        try {
            $service->create($owner, $company->id, (string) Str::uuid(), $booking,
                'Improper second planning item', 'Unverified staff may not write');
            $this->fail('Unverified cached staff created a planning task.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, DB::table('operations_plan_tasks')->count());
        $this->assertSame('planned', DB::table('operations_plan_tasks')
            ->where('id', $task)->value('status'));
        $this->assertSame(1, DB::table('operations_plan_events')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_persisted_account_revocation_blocks_private_planning_page_with_cached_auth(): void
    {
        [, , , , $actors] = $this->fixture();
        $owner = $actors['owner'][0];
        $this->assertTrue(OperationsPlanning::canAccess());
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active);
        $this->assertFalse(OperationsPlanning::canAccess());
        Livewire::test(OperationsPlanning::class)->assertForbidden();

        DB::table('users')->where('id', $owner->id)
            ->update(['is_active' => true, 'email_verified_at' => null]);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse(OperationsPlanning::canAccess());
        Livewire::test(OperationsPlanning::class)->assertForbidden();
        $this->assertSame(0, DB::table('operations_plan_tasks')->count());
        $this->assertSame(0, DB::table('operations_plan_events')->count());
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
