<?php

namespace Tests\Feature;

use App\Filament\Pages\InternalQuickSearch;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\BookingDraftReviewService;
use App\Services\BookingDraftService;
use App\Services\CustomerProfileService;
use App\Services\FinanceControlService;
use App\Services\HallProgramRulesService;
use App\Services\InternalQuickSearchService;
use App\Services\OperationsPlanningService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class InternalQuickSearchTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Internal Search Venue', 'slug' => 'internal-search-venue']);
        $foreign = Company::create(['name' => 'Other Internal Venue', 'slug' => 'other-internal-search']);
        $members = [];
        foreach (['owner', 'admin', 'manager', 'operations'] as $role) {
            $user = User::create(['name' => ucfirst($role),
                'email' => 'search-'.$role.'@example.test', 'password' => 'synthetic']);
            $user->markEmailAsVerified();
            $membership = CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $user->id, 'role' => $role,
            ]);
            $members[$role] = [$user, $membership];
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($members['owner'][0]);

        $hall = Hall::create(['company_id' => $company->id,
            'name' => 'Private Hall', 'slug' => 'private-hall', 'capacity' => 220]);
        foreach (['manager', 'operations'] as $role) {
            HallAssignment::create([
                'company_id' => $company->id,
                'hall_id' => $hall->id,
                'membership_id' => $members[$role][1]->id,
            ]);
        }
        $rule = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 10, 'cleaningMinutes' => 10,
            'graceMinutes' => 10, 'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 0, 'rentalRateMinor' => 0,
        ];
        app(HallProgramRulesService::class)->configure(
            $members['owner'][0], $company->id, $hall->id, [
                'morning' => $rule('08:00', '11:00'),
                'noon' => $rule('12:00', '15:00'),
                'evening' => $rule('17:00', '22:00'),
            ], 'PKR', 0, 'Synthetic only internal search session rules'
        );
        $customer = (string) Str::uuid();
        app(CustomerProfileService::class)->create(
            $members['owner'][0], $company->id, $customer,
            'Private Synthetic Guest', 'contact-hidden@example.test',
            null, 'Synthetic only without live contacts'
        );
        $booking = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $members['owner'][0], $company->id, $booking, $hall->id,
            $customer, '2027-05-20', 'evening', 100, 'Internal private proposal'
        );
        $task = (string) Str::uuid();
        app(OperationsPlanningService::class)->create(
            $members['owner'][0], $company->id, $task,
            $booking, 'Arrange stage light cables', 'Internal setup planning only'
        );
        app(ApprovalWorkflowService::class)->request(
            $members['owner'][0], $company->id, (string) Str::uuid(),
            'expense', 1, 'PKR', null, 'Synthetic expense consideration'
        );
        app(BookingDraftReviewService::class)->submit(
            $members['owner'][0], $company->id, $booking, 1, 'Internal review only'
        );
        $preview = (string) Str::uuid();
        app(FinanceControlService::class)->preview(
            $members['owner'][0], $company->id, $preview, $booking, 2,
            'Frozen nonbinding search fixture'
        );
        return [$company, $foreign, $members, $booking, $task, $preview];
    }

    public function test_owner_uses_private_postgres_search_without_exposing_contacts(): void
    {
        [$company, , $members, $booking, $task, $preview] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $this->assertContains($booking, array_column(
            $service->search($members['owner'][0], $company->id, '2027-05-20'), 'id'
        ));
        $this->assertContains($task, array_column(
            $service->search($members['owner'][0], $company->id, 'stage'), 'id'
        ));
        $this->assertContains('approval', array_column(
            $service->search($members['owner'][0], $company->id, 'expense'), 'kind'
        ));
        $this->assertContains($preview, array_column(
            $service->search($members['owner'][0], $company->id, substr($preview, 0, 8)), 'id'
        ));
        $audits = $service->search($members['owner'][0], $company->id, 'operations');
        $this->assertContains('audit', array_column($audits, 'kind'));
        $results = json_encode(array_merge($audits, $service->search(
            $members['owner'][0], $company->id, 'stage'
        )));
        $this->assertStringNotContainsString('contact-hidden@example.test', $results);
        $this->assertStringNotContainsString('Private Synthetic Guest', $results);
        Livewire::test(InternalQuickSearch::class)
            ->assertSee('Private role-scoped reference search')
            ->set('searchTerm', 'stage')->call('runSearch')->assertHasNoErrors()
            ->assertSee('Arrange stage light cables')
            ->assertDontSee('contact-hidden@example.test');
    }

    public function test_cross_tenant_manager_and_revoked_owner_search_denied(): void
    {
        [$company, $foreign, $members] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $this->be($members['manager'][0]);
        Livewire::test(InternalQuickSearch::class)
            ->assertSee('Private role-scoped reference search')
            ->set('searchTerm', 'stage')->call('runSearch')->assertHasNoErrors()
            ->assertSee('Arrange stage light cables');
        $managerMatches = $service->search($members['manager'][0], $company->id, 'stage');
        $this->assertSame(['planning'], array_values(array_unique(array_column($managerMatches, 'kind'))));
        $this->be($members['owner'][0]);
        try {
            $service->search($members['owner'][0], $foreign->id, 'stage');
            $this->fail('Owner searched an unrelated company.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $mounted = Livewire::test(InternalQuickSearch::class);
        $members['owner'][1]->update(['is_active' => false]);
        $mounted->call('runSearch')->assertForbidden();
    }

    public function test_cached_authenticated_owner_cannot_search_after_persisted_account_shutdown(): void
    {
        [$company, , $members, $booking] = $this->fixture();
        $actor = $members['owner'][0];
        $service = app(InternalQuickSearchService::class);
        $this->assertContains($booking, array_column(
            $service->search($actor, $company->id, '2027-05-20', 20, 'proposal'), 'id'
        ));
        // Mutate the account directly, leaving the current Auth User instance stale.
        \Illuminate\Support\Facades\DB::table('users')->where('id', $actor->id)
            ->update(['is_active' => false]);
        try {
            $service->search($actor, $company->id, '2027-05-20', 20, 'proposal');
            $this->fail('Deactivated account retained search access through cached session.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        \Illuminate\Support\Facades\DB::table('users')->where('id', $actor->id)
            ->update(['is_active' => true, 'email_verified_at' => null]);
        $this->expectException(AuthorizationException::class);
        $service->search($actor, $company->id, '2027-05-20', 20, 'proposal');
    }

    public function test_admin_audit_search_matches_existing_history_policy_and_stays_tenant_scoped(): void
    {
        [$company, $foreign, $members] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $this->be($members['admin'][0]);
        $allowed = $service->search($members['admin'][0], $company->id, 'operations');
        $this->assertContains('audit', array_column($allowed, 'kind'));
        Livewire::test(InternalQuickSearch::class)
            ->set('searchTerm', 'operations')->call('runSearch')->assertHasNoErrors()
            ->assertSee('operations.plan_created');
        $this->expectException(AuthorizationException::class);
        $service->search($members['admin'][0], $foreign->id, 'operations');
    }

    public function test_owner_can_locate_pending_exceptions_and_planned_tasks_by_state(): void
    {
        [$company, , $members, , $task] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $pending = $service->search($members['owner'][0], $company->id, 'pending');
        $this->assertContains('approval', array_column($pending, 'kind'));
        $planned = $service->search($members['owner'][0], $company->id, 'planned');
        $this->assertContains($task, array_column($planned, 'id'));
        $this->assertSame('planned', collect($planned)->firstWhere('id', $task)['status']);
        $this->assertStringNotContainsString('contact-hidden@example.test',
            json_encode(array_merge($pending, $planned), JSON_THROW_ON_ERROR));
    }

    public function test_manager_and_operations_staff_see_only_current_assigned_hall_plans(): void
    {
        [$company, $foreign, $members, $booking, $assignedTask] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $this->be($members['owner'][0]);
        $hall = Hall::create(['company_id' => $company->id,
            'name' => 'Unassigned Second Hall', 'slug' => 'unassigned-second-hall', 'capacity' => 100]);
        $program = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 10, 'cleaningMinutes' => 10,
            'graceMinutes' => 10, 'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 0, 'rentalRateMinor' => 0,
        ];
        app(HallProgramRulesService::class)->configure(
            $members['owner'][0], $company->id, $hall->id, [
                'morning' => $program('08:00', '11:00'),
                'noon' => $program('12:00', '15:00'),
                'evening' => $program('17:00', '22:00'),
            ], 'PKR', 0, 'Unassigned synthetic hall rules'
        );
        $customerId = \Illuminate\Support\Facades\DB::table('booking_drafts')
            ->where('id', $booking)->value('customer_id');
        $otherBooking = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $members['owner'][0], $company->id, $otherBooking, $hall->id, $customerId,
            '2027-05-21', 'noon', 50, 'Unassigned hall proposal'
        );
        $unassignedTask = (string) Str::uuid();
        app(OperationsPlanningService::class)->create(
            $members['owner'][0], $company->id, $unassignedTask, $otherBooking,
            'Arrange stage lighting in other hall', 'Unassigned private planning'
        );
        foreach (['manager', 'operations'] as $role) {
            $this->be($members[$role][0]);
            $matches = $service->search($members[$role][0], $company->id, 'stage');
            $this->assertContains($assignedTask, array_column($matches, 'id'));
            $this->assertNotContains($unassignedTask, array_column($matches, 'id'));
            $this->assertSame(['planning'], array_values(array_unique(array_column($matches, 'kind'))));
            $this->assertSame([], $service->search($members[$role][0], $company->id, 'expense'));
            $this->assertSame([], $service->search($members[$role][0], $company->id, '2027-05-20'));
            try {
                $service->search($members[$role][0], $foreign->id, 'stage');
                $this->fail('Foreign tenant search permitted.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
            Livewire::test(InternalQuickSearch::class)
                ->set('searchTerm', 'stage')->call('runSearch')->assertHasNoErrors()
                ->assertSee('Arrange stage light cables')
                ->assertDontSee('Arrange stage lighting in other hall');
        }
        $members['operations'][1]->hallAssignments()->delete();
        $this->be($members['operations'][0]);
        $this->assertSame([], $service->search($members['operations'][0], $company->id, 'stage'));
        Livewire::test(InternalQuickSearch::class)
            ->set('searchTerm', 'stage')->call('runSearch')->assertHasNoErrors()
            ->assertDontSee('Arrange stage light cables');
    }

    public function test_search_workspace_links_preserve_company_for_authorized_destinations(): void
    {
        [$company, , $members, , $task] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $planning = collect($service->search($members['owner'][0], $company->id, 'stage'))
            ->firstWhere('id', $task);
        $this->assertSame('/admin/operations-planning?company='.$company->id, $planning['path']);
        $approval = collect($service->search($members['owner'][0], $company->id, 'expense'))
            ->firstWhere('kind', 'approval');
        $this->assertSame('/admin/approval-center?company='.$company->id
            .'&request='.$approval['id'], $approval['path']);
        $this->be($members['operations'][0]);
        $staffPlanning = collect($service->search($members['operations'][0], $company->id, 'stage'))
            ->firstWhere('id', $task);
        $this->assertSame($planning['path'], $staffPlanning['path']);
    }

    public function test_record_type_filters_are_server_authorized_and_reset_stale_results(): void
    {
        [$company, , $members] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $onlyApprovals = $service->search($members['owner'][0], $company->id, 'expense', 20, 'approval');
        $this->assertNotEmpty($onlyApprovals);
        $this->assertSame(['approval'], array_values(array_unique(array_column($onlyApprovals, 'kind'))));
        $this->assertSame([], $service->search($members['owner'][0], $company->id, 'expense', 20, 'planning'));
        Livewire::test(InternalQuickSearch::class)
            ->set('searchTerm', 'stage')->set('searchKind', 'planning')
            ->call('runSearch')->assertHasNoErrors()->assertSee('Arrange stage light cables')
            ->set('searchKind', 'approval')->assertDontSee('Arrange stage light cables')
            ->call('runSearch')->assertHasNoErrors()->assertDontSee('Arrange stage light cables');
        try {
            $service->search($members['owner'][0], $company->id, 'stage', 20, 'forged');
            $this->fail('Unrecognized category was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->be($members['manager'][0]);
        Livewire::test(InternalQuickSearch::class)
            ->assertSee('Planning tasks')
            ->assertDontSee('Nonbinding finance previews')
            ->set('searchTerm', 'expense')->set('searchKind', 'finance')
            ->call('runSearch')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $service->search($members['manager'][0], $company->id, 'expense', 20, 'audit');
    }

    public function test_manager_sees_only_own_approval_requests_not_other_staff_requests(): void
    {
        [$company, , $members, $booking] = $this->fixture();
        $this->be($members['manager'][0]);
        $mine = (string) Str::uuid();
        app(ApprovalWorkflowService::class)->request(
            $members['manager'][0], $company->id, $mine,
            'discount', 150, 'PKR', $booking,
            'Own synthetic additional discount request'
        );
        $service = app(InternalQuickSearchService::class);
        $ownResults = $service->search($members['manager'][0],
            $company->id, 'discount', 20, 'approval');
        $this->assertContains($mine, array_column($ownResults, 'id'));
        $this->assertSame(['approval'], array_values(array_unique(array_column($ownResults, 'kind'))));
        $this->assertSame('/admin/approval-center?company='.$company->id
            .'&request='.$mine, $ownResults[0]['path']);
        $this->assertSame([], $service->search($members['manager'][0],
            $company->id, 'expense', 20, 'approval'));
        Livewire::test(InternalQuickSearch::class)
            ->assertSee('My approval requests')
            ->set('searchTerm', 'discount')->set('searchKind', 'approval')
            ->call('runSearch')->assertHasNoErrors()
            ->assertSee('Reference '.$mine)
            ->assertDontSee('Synthetic expense consideration');

        $this->be($members['operations'][0]);
        $this->assertSame([], $service->search($members['operations'][0],
            $company->id, 'discount'));
        Livewire::test(InternalQuickSearch::class)->assertDontSee('My approval requests');
        $this->expectException(AuthorizationException::class);
        $service->search($members['operations'][0], $company->id, 'discount', 20, 'approval');
    }

    public function test_all_allowed_owner_destinations_carry_authorized_company_context(): void
    {
        [$company, , $members, $booking, , $preview] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        foreach ([
            ['term' => '2027-05-20', 'kind' => 'proposal', 'path' => '/admin/booking-proposals'],
            ['term' => substr($preview, 0, 8), 'kind' => 'finance', 'path' => '/admin/finance-review'],
            ['term' => 'operations', 'kind' => 'audit', 'path' => '/admin/audit-history'],
        ] as $item) {
            $row = collect($service->search($members['owner'][0], $company->id, $item['term']))
                ->firstWhere('kind', $item['kind']);
            $this->assertNotNull($row);
            $expected = $item['path'].'?company='.$company->id
                .($item['kind'] === 'proposal' ? '&proposal='.$booking : '');
            $this->assertSame($expected, $row['path']);
        }
    }

    public function test_owner_search_opens_exact_company_scoped_proposal_reference(): void
    {
        [$company, , $members, $booking] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $row = collect($service->search(
            $members['owner'][0], $company->id, substr($booking, 0, 8), 20, 'proposal'
        ))->firstWhere('id', $booking);
        $this->assertNotNull($row);
        $this->assertSame('/admin/booking-proposals?company='.$company->id
            .'&proposal='.$booking, $row['path']);
        $this->assertStringNotContainsString('contact-hidden@example.test',
            json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Private Synthetic Guest',
            json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function test_term_and_result_bounds_reject_contact_and_large_queries(): void
    {
        [$company, , $members] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        foreach (['', 'ab', 'user@example.test', str_repeat('a', 81)] as $term) {
            try {
                $service->search($members['owner'][0], $company->id, $term);
                $this->fail('Invalid query was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $service->search($members['owner'][0], $company->id, 'stage', 41);
    }
}
