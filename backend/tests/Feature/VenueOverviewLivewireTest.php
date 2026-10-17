<?php

namespace Tests\Feature;

use App\Filament\Pages\VenueOverview;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class VenueOverviewLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Simple Hall Workspace', 'slug' => 'overview-primary']);
        $other = Company::create(['name' => 'Private Other Venue', 'slug' => 'overview-foreign']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Main Hall',
            'slug' => 'main-overview', 'capacity' => 300]);
        $people = [];
        foreach (['owner', 'admin', 'manager', 'operations'] as $role) {
            $user = User::create(['name' => ucfirst($role),
                'email' => 'overview-'.$role.'@example.test', 'password' => 'test-only']);
            $user->markEmailAsVerified();
            $membership = CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $user->id, 'role' => $role,
            ]);
            $people[$role] = $user;
            if (in_array($role, ['manager', 'operations'], true)) {
                HallAssignment::create(['company_id' => $company->id, 'hall_id' => $hall->id,
                    'membership_id' => $membership->id]);
            }
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($people['owner']);
        return [$company, $other, $hall, $people];
    }

    public function test_owner_has_company_aggregate_and_private_navigation_without_contacts(): void
    {
        [$company, , $hall, $people] = $this->fixture();
        $this->be($people['admin']);
        app(ApprovalWorkflowService::class)->request($people['admin'], $company->id,
            (string) Str::uuid(), 'expense', 125, 'PKR', null, 'Synthetic private supplies');
        $this->be($people['owner']);
        Livewire::test(VenueOverview::class)
            ->assertSet('companyId', $company->id)
            ->assertSet('summary.role', 'owner')
            ->assertSet('summary.visibleHalls', 1)
            ->assertSet('summary.hallsNeedingSetup', 1)
            ->assertSet('summary.pendingApprovals', 1)
            ->assertSee('Review pending approvals')
            ->assertSet('summary.drafts', 0)
            ->assertSee('Review booking proposals')
            ->assertSee('Approval center')
            ->assertSee('Finance review · internal only')
            ->assertSee('Website editor')
            ->assertSee('Universal staff search')
            ->assertDontSee('Synthetic private supplies')
            ->assertDontSee('overview-admin@example.test');
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_owner_hall_program_setup_pulse_changes_after_saved_three_program_rules(): void
    {
        [$company, , $hall, $people] = $this->fixture();
        Livewire::test(VenueOverview::class)
            ->assertSet('summary.hallsNeedingSetup', 1)
            ->assertSee('Halls needing program setup');

        $slot = static fn (string $from, string $to): array => [
            'start' => $from, 'end' => $to,
            'setupMinutes' => 10, 'cleaningMinutes' => 10,
            'graceMinutes' => 10, 'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 0, 'rentalRateMinor' => 0,
        ];
        app(\App\Services\HallProgramRulesService::class)->configure(
            $people['owner'], $company->id, $hall->id,
            ['morning' => $slot('08:00', '11:00'),
                'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00')],
            'PKR', 0, 'Synthetic owner program setup pulse'
        );
        Livewire::test(VenueOverview::class)
            ->assertSet('summary.hallsNeedingSetup', 0)
            ->assertSet('summary.visibleHalls', 1);
        $this->be($people['manager']);
        Livewire::test(VenueOverview::class)
            ->assertSet('summary.hallsNeedingSetup', null)
            ->assertDontSee('Halls needing program setup');
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_manager_planning_count_only_includes_assigned_active_halls(): void
    {
        [$company, , $assignedHall, $people] = $this->fixture();
        $unassigned = Hall::create(['company_id' => $company->id,
            'name' => 'Owner only hall', 'slug' => 'private-overview',
            'capacity' => 200]);
        $customer = (string) Str::uuid();
        DB::table('customer_profiles')->insert([
            'id' => $customer, 'company_id' => $company->id,
            'created_by' => $people['owner']->id,
            'name_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString('Hidden customer'),
        ]);
        foreach ([
            [$assignedHall, 'planned'], [$assignedHall, 'reviewed'],
            [$unassigned, 'planned'],
        ] as [$hall, $status]) {
            $booking = (string) Str::uuid();
            DB::table('booking_drafts')->insert([
                'id' => $booking, 'company_id' => $company->id,
                'hall_id' => $hall->id, 'customer_id' => $customer,
                'event_date' => '2027-03-05', 'program' => 'morning',
                'requested_guests' => 50, 'status' => 'draft',
                'created_by' => $people['owner']->id, 'revision' => 1,
            ]);
            DB::table('operations_plan_tasks')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company->id,
                'booking_id' => $booking, 'hall_id' => $hall->id,
                'title' => 'Synthetic venue setup', 'status' => $status,
                'revision' => 1, 'creation_reason' => 'Private setup planning test',
                'created_by' => $people['owner']->id,
                'updated_by' => $people['owner']->id,
            ]);
        }

        $ownerPanel = Livewire::test(VenueOverview::class)
            ->assertSet('summary.role', 'owner')
            ->assertSet('summary.plannedTasks', 2)
            ->assertSee('Recent internal setup plans')
            ->assertSee('Owner only hall')
            ->assertSee('Open this proposal')
            ->assertSee('Setup planning tasks to review')
            ->assertDontSee('Hidden customer');
        $this->assertCount(3, $ownerPanel->get('summary')['recentPlans']);
        $this->be($people['manager']);
        $managerPanel = Livewire::test(VenueOverview::class)
            ->assertSet('summary.visibleHalls', 1)
            ->assertSet('summary.plannedTasks', 1)
            ->assertSee('Recent internal setup plans')
            ->assertSee('Setup planning tasks to review')
            ->assertDontSee('Owner only hall')
            ->assertDontSee('Hidden customer');
        $this->assertCount(2, $managerPanel->get('summary')['recentPlans']);

        $this->be($people['operations']);
        $operationsPanel = Livewire::test(VenueOverview::class)
            ->assertSet('summary.visibleHalls', 1)
            ->assertSet('summary.plannedTasks', 1);
        $this->assertCount(2, $operationsPanel->get('summary')['recentPlans']);
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_owner_upcoming_week_counts_only_current_company_active_drafts_not_holds(): void
    {
        [$company, $foreign, $hall, $people] = $this->fixture();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        try {
            $customer = (string) Str::uuid();
            DB::table('customer_profiles')->insert([
                'id' => $customer, 'company_id' => $company->id,
                'created_by' => $people['owner']->id,
                'name_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString('Synthetic Private Name'),
            ]);
            foreach ([
                ['2026-11-10', 'draft'],
                ['2026-11-16', 'under_review'],
                ['2026-11-17', 'draft'],
                ['2026-11-13', 'withdrawn'],
            ] as [$date, $status]) {
                DB::table('booking_drafts')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $company->id,
                    'hall_id' => $hall->id, 'customer_id' => $customer,
                    'event_date' => $date, 'program' => 'evening',
                    'requested_guests' => 100, 'status' => $status,
                    'created_by' => $people['owner']->id, 'revision' => 1,
                ]);
            }
            $panel = Livewire::test(VenueOverview::class)
                ->assertSet('summary.drafts', 3)
                ->assertSet('summary.upcomingDrafts', 2)
                ->assertSet('summary.upcomingFrom', '2026-11-10')
                ->assertSet('summary.upcomingThrough', '2026-11-16')
                ->assertSee('Next 7 days')
                ->assertSee('Review upcoming drafts (not reserved)')
                ->assertSee('Recent unconfirmed proposals')
                ->assertSee('Review this unconfirmed proposal')
                ->assertDontSee('Synthetic Private Name');
            $this->assertCount(3, $panel->get('summary')['recentProposals']);
            $this->assertSame(0, DB::table('hall_slot_claims')->count());
            $this->assertSame(0, DB::table('finance_journals')->count());

            $this->be($people['manager']);
            Livewire::test(VenueOverview::class)
                ->assertSet('summary.upcomingDrafts', null)
                ->assertSet('summary.upcomingFrom', null)
                ->assertSet('summary.recentProposals', [])
                ->assertDontSee('Recent unconfirmed proposals')
                ->assertDontSee('Review upcoming drafts (not reserved)');
        } finally {
            $this->travelBack();
        }
    }

    public function test_manager_aggregate_is_own_approval_only_and_company_draft_hidden(): void
    {
        [$company, $other, , $people] = $this->fixture();
        $this->be($people['admin']);
        app(ApprovalWorkflowService::class)->request($people['admin'], $company->id,
            (string) Str::uuid(), 'expense', 125, 'PKR', null, 'Private owner exception');
        $this->be($people['manager']);
        Livewire::test(VenueOverview::class)
            ->assertSet('summary.role', 'manager')
            ->assertSet('summary.visibleHalls', 1)
            ->assertSet('summary.hallsNeedingSetup', null)
            ->assertSet('summary.pendingApprovals', 0)
            ->assertSet('summary.drafts', null)
            ->assertDontSee('Unconfirmed company proposals')
            ->assertDontSee('Review booking proposals')
            ->assertDontSee('Finance review · internal only')
            ->assertDontSee('Private owner exception')
            ->assertSee('Hall calendar')
            ->assertSee('Operations planning')
            ->set('companyId', $other->id)->assertForbidden();
    }

    public function test_existing_overview_session_loses_company_wide_counts_after_role_downgrade(): void
    {
        [$company, , , $people] = $this->fixture();
        $actor = $people['owner'];
        $component = Livewire::test(VenueOverview::class)
            ->assertSet('summary.role', 'owner')
            ->assertSet('summary.drafts', 0)
            ->assertSee('Review booking proposals')
            ->assertSee('Finance review · internal only');

        CompanyMembership::query()->where('company_id', $company->id)
            ->where('user_id', $actor->id)->update(['role' => 'manager']);
        // No new login or explicit "Refresh overview" should be required:
        // hydration must re-evaluate the stored membership before rendering.
        $component->call('companyOptions')
            ->assertSet('summary.role', 'manager')
            ->assertSet('summary.drafts', null)
            ->assertSet('summary.canReviewFinance', false)
            ->assertDontSee('Review booking proposals')
            ->assertDontSee('Finance review · internal only');
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_current_persisted_account_revocation_blocks_overview_even_with_cached_login(): void
    {
        [, , , $people] = $this->fixture();
        $actor = $people['owner'];
        $this->assertTrue(VenueOverview::canAccess());
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue($actor->is_active);
        $this->assertFalse(VenueOverview::canAccess());
        Livewire::test(VenueOverview::class)->assertForbidden();
        DB::table('users')->where('id', $actor->id)
            ->update(['is_active' => true, 'email_verified_at' => null]);
        $this->assertFalse(VenueOverview::canAccess());
        Livewire::test(VenueOverview::class)->assertForbidden();
    }

    public function test_operations_role_sees_only_assigned_halls_and_planning_not_approvals(): void
    {
        [$company, , $hall, $people] = $this->fixture();
        $this->be($people['operations']);
        Livewire::test(VenueOverview::class)
            ->assertSet('summary.role', 'operations')
            ->assertSet('summary.visibleHalls', 1)
            ->assertSet('summary.pendingApprovals', null)
            ->assertSet('summary.drafts', null)
            ->assertDontSee('Approval center')
            ->assertDontSee('Review booking proposals')
            ->assertSee('Operations planning');
    }
}
