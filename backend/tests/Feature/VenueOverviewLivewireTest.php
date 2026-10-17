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
            ->assertSet('summary.pendingApprovals', 1)
            ->assertSet('summary.drafts', 0)
            ->assertSee('Review booking proposals')
            ->assertSee('Approval center')
            ->assertSee('Website editor')
            ->assertSee('Universal staff search')
            ->assertDontSee('Synthetic private supplies')
            ->assertDontSee('overview-admin@example.test');
        $this->assertSame(0, DB::table('finance_journals')->count());
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
            ->assertSet('summary.pendingApprovals', 0)
            ->assertSet('summary.drafts', null)
            ->assertDontSee('Unconfirmed company proposals')
            ->assertDontSee('Review booking proposals')
            ->assertDontSee('Private owner exception')
            ->assertSee('Hall calendar')
            ->assertSee('Operations planning')
            ->set('companyId', $other->id)->assertForbidden();
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
