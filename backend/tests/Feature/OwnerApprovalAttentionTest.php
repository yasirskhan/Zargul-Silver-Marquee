<?php

namespace Tests\Feature;

use App\Livewire\OwnerApprovalAttention;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OwnerApprovalAttentionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Visible Demo Hall', 'slug' => 'owner-notices-one']);
        $foreign = Company::create(['name' => 'Private Other Hall', 'slug' => 'owner-notices-two']);
        $actors = [];
        foreach (['owner', 'admin', 'manager'] as $role) {
            $user = User::create([
                'name' => ucfirst($role),
                'email' => 'owner-notices-'.$role.'@example.test',
                'password' => 'synthetic-only',
            ]);
            $user->markEmailAsVerified();
            CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $user->id,
                'role' => $role,
            ]);
            $actors[$role] = $user;
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        return [$company, $foreign, $actors];
    }

    private function expense(User $actor, string $companyId, string $reason): string
    {
        $this->be($actor);
        $id = (string) Str::uuid();
        app(ApprovalWorkflowService::class)->request(
            $actor, $companyId, $id, 'expense', 125, 'PKR', null, $reason
        );
        return $id;
    }

    public function test_owner_modal_shows_only_other_staff_pending_company_counts_and_can_dismiss(): void
    {
        [$company, $foreign, $people] = $this->fixture();
        $adminRequestId = $this->expense($people['admin'], $company->id, 'Internal supply request');
        $this->expense($people['owner'], $company->id, 'Owners own proposed cost');
        $this->be($people['owner']);
        $pending = app(ApprovalWorkflowService::class)->ownerPendingAttention($people['owner']);
        $this->assertCount(1, $pending['items']);
        $this->assertSame(1, $pending['items'][0]['pending']);
        $this->assertSame('Visible Demo Hall', $pending['items'][0]['company']);
        $this->assertSame($adminRequestId, $pending['items'][0]['latestRequestId']);
        $this->assertNotEmpty($pending['fingerprint']);

        Livewire::test(OwnerApprovalAttention::class)
            ->assertSee('Pending owner approval requests')
            ->assertSee('Visible Demo Hall')
            ->assertSee('Review one pending request directly')
            ->assertSee('request='.urlencode($adminRequestId), false)
            ->assertDontSee('Private Other Hall')
            ->assertDontSee('Internal supply request')
            ->assertDontSee('Owners own proposed cost')
            ->call('dismiss')
            ->assertDontSee('Pending owner approval requests')
            ->assertSet('open', false);

        Livewire::test(OwnerApprovalAttention::class)
            ->assertDontSee('Pending owner approval requests');
        $this->expense($people['admin'], $company->id, 'Another separate request');
        $this->be($people['owner']);
        Livewire::test(OwnerApprovalAttention::class)
            ->assertSee('2 pending requests');
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_new_login_clears_only_previous_owner_attention_dismissal(): void
    {
        [$company, , $people] = $this->fixture();
        $this->expense($people['admin'], $company->id, 'Pending on next owner login');
        $this->be($people['owner']);
        $component = Livewire::test(OwnerApprovalAttention::class)
            ->assertSee('Pending owner approval requests')->call('dismiss')
            ->assertDontSee('Pending owner approval requests');
        $key = 'venuedesk.owner_approval_notice_dismissed.'.$people['owner']->id;
        $this->assertNotNull(session($key));
        // A real Laravel login event, never mock out or bypass the MFA flow.
        request()->setLaravelSession(app('session')->driver());
        \Illuminate\Support\Facades\Event::dispatch(
            new \Illuminate\Auth\Events\Login('web', $people['owner'], false)
        );
        $this->assertNull(session($key));
        Livewire::test(OwnerApprovalAttention::class)
            ->assertSee('Pending owner approval requests');
    }

    public function test_manager_and_nonowner_cannot_get_owner_attention_or_cross_tenant_records(): void
    {
        [$company, $foreign, $people] = $this->fixture();
        $outsider = User::create([
            'name' => 'Other Admin', 'email' => 'other-owner-notices@example.test',
            'password' => 'synthetic-only',
        ]);
        $outsider->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $foreign->id, 'user_id' => $outsider->id, 'role' => 'admin']);
        $this->expense($outsider, $foreign->id, 'Private foreign cost');
        $this->expense($people['admin'], $company->id, 'Internal company cost');

        $this->be($people['manager']);
        $this->assertSame([], app(ApprovalWorkflowService::class)
            ->ownerPendingAttention($people['manager'])['items']);
        Livewire::test(OwnerApprovalAttention::class)->assertDontSee('Pending owner approval requests');

        $this->be($people['owner']);
        $own = app(ApprovalWorkflowService::class)->ownerPendingAttention($people['owner']);
        $this->assertCount(1, $own['items']);
        $this->assertSame($company->id, $own['items'][0]['companyId']);
        Livewire::test(OwnerApprovalAttention::class)->assertDontSee('Private foreign cost');
        $people['owner']->forceFill(['email_verified_at' => null])->save();
        $this->expectException(AuthorizationException::class);
        app(ApprovalWorkflowService::class)->ownerPendingAttention($people['owner']);
    }

    public function test_revoked_owner_hides_attention_during_existing_livewire_session(): void
    {
        [$company, , $people] = $this->fixture();
        $this->expense($people['admin'], $company->id, 'Synthetic owner review');
        $this->be($people['owner']);
        $component = Livewire::test(OwnerApprovalAttention::class)
            ->assertSee('Pending owner approval requests');
        CompanyMembership::query()->where('company_id', $company->id)
            ->where('user_id', $people['owner']->id)->update(['is_active' => false]);
        $component->call('dismiss')->assertSet('open', false)
            ->assertDontSee('Pending owner approval requests');
    }
}
