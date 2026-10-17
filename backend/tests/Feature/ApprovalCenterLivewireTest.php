<?php

namespace Tests\Feature;

use App\Filament\Pages\ApprovalCenter;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalCenterLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Company A', 'slug' => 'approval-ui']);
        $foreign = Company::create(['name' => 'Private B', 'slug' => 'approval-ui-other']);
        $members = [];
        foreach (['owner', 'admin', 'manager'] as $role) {
            $user = User::create([
                'name' => ucfirst($role),
                'email' => 'approval-ui-'.$role.'@example.test', 'password' => 'test-only',
            ]);
            $user->markEmailAsVerified();
            $membership = CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $user->id, 'role' => $role,
            ]);
            $members[$role] = [$user, $membership];
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($members['owner'][0]);

        return [$company, $foreign, $members];
    }

    public function test_panel_owner_can_submit_and_review_admin_expense_without_money_mutation(): void
    {
        [$company, , $members] = $this->fixture();
        $admin = $members['admin'][0];
        $this->be($admin);
        Livewire::test(ApprovalCenter::class)
            ->assertSee('Request approval')
            ->set('newCategory', 'expense')->set('newAmount', '250')
            ->set('newCurrency', 'PKR')->set('newBookingId', '')
            ->set('newReason', 'Proposed supplies expense')
            ->call('submitRequest')->assertHasNoErrors();
        $approval = DB::table('approval_requests')->where('company_id', $company->id)->first();
        $this->assertNotNull($approval);
        $this->assertSame('pending', $approval->status);

        $this->be($members['owner'][0]);
        Livewire::test(ApprovalCenter::class)
            ->assertSee('Proposed supplies expense')
            ->assertSee('Requested by Admin')
            ->call('selectRequest', $approval->id)
            ->set('decisionReason', 'Owner reviewed proposed expense')
            ->call('approve')->assertHasNoErrors()
            ->assertSee('approved');
        $this->assertSame('approved', DB::table('approval_requests')->where('id', $approval->id)->value('status'));
        $this->assertSame(1, DB::table('approval_decisions')->count());
        $this->assertSame(2, DB::table('approval_alert_outbox')->count());
    }




    public function test_selected_owner_approval_shows_complete_nonapplying_request_details(): void
    {
        [$company, , $members] = $this->fixture();
        $admin = $members['admin'][0];
        $this->be($admin);
        $id = (string) \Illuminate\Support\Str::uuid();
        app(\App\Services\ApprovalWorkflowService::class)->request(
            $admin, $company->id, $id, 'expense', 475, 'PKR', null,
            'Synthetic lighting repair exception'
        );
        $this->be($members['owner'][0]);
        $panel = Livewire::test(ApprovalCenter::class)
            ->call('selectRequest', $id)
            ->assertSee('Selected exception details')
            ->assertSee('Proposed exception amount:')
            ->assertSee('475 minor units')
            ->assertSee('Requester:')
            ->assertSee('Admin')
            ->assertSee('Synthetic lighting repair exception')
            ->assertSee('This decision does not change any payment, booking or price.');
        $this->assertSame($id, $panel->instance()->selectedRequest()['id']);
        $panel->set('statusFilter', 'approved')
            ->assertSet('selectedId', null)
            ->assertDontSee('Selected exception details');
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_select_request_never_allows_review_of_already_decided_approval(): void
    {
        [$company, , $members] = $this->fixture();
        $admin = $members['admin'][0];
        $owner = $members['owner'][0];
        $service = app(\App\Services\ApprovalWorkflowService::class);
        $this->be($admin);
        $id = (string) \Illuminate\Support\Str::uuid();
        $service->request($admin, $company->id, $id, 'expense', 300, 'PKR', null,
            'Previously decided synthetic expense');
        $this->be($owner);
        $service->decide($owner, $company->id, $id, 1, 'approved',
            'Owner already resolved proposed expense');
        Livewire::test(ApprovalCenter::class)
            ->assertSee('Previously decided synthetic expense')
            ->call('selectRequest', $id)->assertForbidden();
        $this->assertSame(1, DB::table('approval_decisions')->count());
        $this->assertSame('approved', DB::table('approval_requests')->where('id', $id)->value('status'));
    }


    public function test_owner_category_filter_clears_selected_decision_without_mutation(): void
    {
        [$company, , $members] = $this->fixture();
        $admin = $members['admin'][0];
        $this->be($admin);
        $id = (string) \Illuminate\Support\Str::uuid();
        app(\App\Services\ApprovalWorkflowService::class)->request(
            $admin, $company->id, $id, 'expense', 500, 'PKR', null,
            'Synthetic supply category review');
        $this->be($members['owner'][0]);
        Livewire::test(ApprovalCenter::class)
            ->assertSee('Exception category filter')
            ->call('selectRequest', $id)->assertSet('selectedId', $id)
            ->set('categoryFilter', 'discount')
            ->assertSet('inboxOffset', 0)->assertSet('entries', [])
            ->assertSet('selectedId', null)
            ->assertDontSee('Selected exception details')
            ->set('categoryFilter', 'expense')
            ->assertSee('Synthetic supply category review')
            ->set('categoryFilter', 'unknown')
            ->assertHasErrors(['categoryFilter']);
        $this->assertSame('pending', DB::table('approval_requests')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('approval_decisions')->count());
    }

    public function test_owner_pages_old_approval_requests_without_losing_tenant_or_decision_scope(): void
    {
        [$company, , $members] = $this->fixture();
        $service = app(\App\Services\ApprovalWorkflowService::class);
        $admin = $members['admin'][0];
        $this->be($admin);
        for ($i = 0; $i < 32; $i++) {
            $service->request($admin, $company->id, (string) \Illuminate\Support\Str::uuid(),
                'expense', 100 + $i, 'PKR', null, 'Paged synthetic approval '.($i + 1));
        }
        $this->be($members['owner'][0]);
        $page = Livewire::test(ApprovalCenter::class)
            ->assertSee('Next 30')
            ->assertSet('inboxOffset', 0)
            ->assertSet('inboxHasMore', true);
        $firstIds = array_column($page->get('entries'), 'id');
        $this->assertCount(30, $firstIds);
        $page->call('nextInboxPage')
            ->assertSet('inboxOffset', 30)
            ->assertSet('inboxHasMore', false);
        $olderIds = array_column($page->get('entries'), 'id');
        $this->assertCount(2, $olderIds);
        $this->assertSame([], array_values(array_intersect($firstIds, $olderIds)));
        $page->call('nextInboxPage')->assertSet('inboxOffset', 30);
        $page->call('previousInboxPage')->assertSet('inboxOffset', 0);
        $this->assertSame($firstIds, array_column($page->get('entries'), 'id'));
        $page->set('statusFilter', 'approved')
            ->assertSet('inboxOffset', 0)->assertSet('entries', []);
        $page->call('nextInboxPage')->assertSet('inboxOffset', 0);
        $this->assertSame(32, DB::table('approval_requests')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_owner_can_filter_scoped_approval_inbox_and_never_review_hidden_selection(): void
    {
        [$company, , $members] = $this->fixture();
        $admin = $members['admin'][0];
        $owner = $members['owner'][0];
        $service = app(\App\Services\ApprovalWorkflowService::class);
        $this->be($admin);
        $approved = (string) \Illuminate\Support\Str::uuid();
        $pending = (string) \Illuminate\Support\Str::uuid();
        $service->request($admin, $company->id, $approved,
            'expense', 300, 'PKR', null, 'Old approved synthetic expense');
        $service->request($admin, $company->id, $pending,
            'expense', 100, 'PKR', null, 'New pending synthetic expense');
        $this->be($owner);
        $service->decide($owner, $company->id, $approved, 1, 'approved',
            'Owner approved synthetic planning request');

        $component = Livewire::test(ApprovalCenter::class)
            ->assertSee('Old approved synthetic expense')
            ->assertSee('New pending synthetic expense')
            ->set('statusFilter', 'pending')
            ->assertSet('statusFilter', 'pending')
            ->assertSee('New pending synthetic expense')
            ->assertDontSee('Old approved synthetic expense');
        $this->assertSame([$pending], array_column($component->get('entries'), 'id'));
        $component->call('selectRequest', $pending)
            ->assertSet('selectedId', $pending)
            ->set('statusFilter', 'approved')
            ->assertSet('selectedId', null)
            ->assertSet('selectedRevision', 0)
            ->assertSee('Old approved synthetic expense')
            ->assertDontSee('New pending synthetic expense');
        $component->set('decisionReason', 'Must not decide hidden request')
            ->call('approve')->assertNotFound();
        $component = Livewire::test(ApprovalCenter::class)
            ->set('statusFilter', 'rejected')
            ->assertSet('entries', [])
            ->set('statusFilter', 'invalid')
            ->assertHasErrors(['statusFilter']);
        $this->assertSame('pending', DB::table('approval_requests')->where('id', $pending)->value('status'));
        $this->assertSame(1, DB::table('approval_decisions')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_private_approval_reference_link_opens_only_current_company_request(): void
    {
        [$company, $foreign, $members] = $this->fixture();
        $admin = $members['admin'][0];
        $this->be($admin);
        $service = app(\App\Services\ApprovalWorkflowService::class);
        $first = (string) \Illuminate\Support\Str::uuid();
        $second = (string) \Illuminate\Support\Str::uuid();
        $service->request($admin, $company->id, $first, 'expense', 100, 'PKR', null, 'First synthetic request');
        $service->request($admin, $company->id, $second, 'expense', 150, 'PKR', null, 'Second synthetic request');
        $this->be($members['owner'][0]);
        $component = Livewire::withQueryParams(['company' => $company->id, 'request' => $second])
            ->test(ApprovalCenter::class)
            ->assertSet('requestReference', $second)
            ->assertSee('Second synthetic request')
            ->assertDontSee('First synthetic request');
        $this->assertCount(1, $component->get('entries'));
        $component->call('clearRequestReference')->assertSet('requestReference', '')
            ->assertSee('First synthetic request')->assertSee('Second synthetic request');
        Livewire::withQueryParams(['company' => $company->id, 'request' => (string) \Illuminate\Support\Str::uuid()])
            ->test(ApprovalCenter::class)->assertSet('entries', []);
        Livewire::withQueryParams(['company' => $foreign->id, 'request' => $second])
            ->test(ApprovalCenter::class)->assertForbidden();
        $this->assertSame(0, DB::table('approval_decisions')->count());
    }

    public function test_changing_request_reference_clears_previously_selected_approval_decision(): void
    {
        [$company, , $members] = $this->fixture();
        $admin = $members['admin'][0];
        $this->be($admin);
        $id = (string) \Illuminate\Support\Str::uuid();
        app(\App\Services\ApprovalWorkflowService::class)->request(
            $admin, $company->id, $id, 'expense', 200, 'PKR', null,
            'Nonapplying synthetic approval request'
        );
        $this->be($members['owner'][0]);
        $component = Livewire::test(ApprovalCenter::class)
            ->call('selectRequest', $id)->assertSet('selectedId', $id)
            ->set('requestReference', (string) \Illuminate\Support\Str::uuid())
            ->assertSet('selectedId', null)
            ->assertSet('selectedRevision', 0);
        $component->set('decisionReason', 'Must not decide an old selection')
            ->call('approve')->assertNotFound();
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame('pending', DB::table('approval_requests')->where('id', $id)->value('status'));
    }

    public function test_requester_cannot_select_their_own_pending_decision_in_panel(): void
    {
        [$company, , $members] = $this->fixture();
        $owner = $members['owner'][0];
        $key = (string) \Illuminate\Support\Str::uuid();
        app(\App\Services\ApprovalWorkflowService::class)->request(
            $owner, $company->id, $key, 'expense', 125, 'PKR', null,
            'Synthetic owner-requested supplies'
        );
        Livewire::test(ApprovalCenter::class)
            ->assertSee('Synthetic owner-requested supplies')
            ->assertDontSee('Review decision')
            ->call('selectRequest', $key)->assertForbidden();
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame('pending', DB::table('approval_requests')->where('id', $key)->value('status'));
    }

    public function test_switching_owner_company_clears_old_company_unsent_approval_and_grant_forms(): void
    {
        [$company, , $members] = $this->fixture();
        $other = Company::create(['name' => 'Second owned hall', 'slug' => 'approval-switch-owned']);
        CompanyMembership::create(['company_id' => $other->id,
            'user_id' => $members['owner'][0]->id, 'role' => 'owner']);
        Livewire::test(ApprovalCenter::class)
            ->set('newBookingId', (string) \Illuminate\Support\Str::uuid())
            ->set('newReason', 'Company specific reason')
            ->set('newAmount', '500')
            ->set('grantAdminId', (string) $members['admin'][0]->id)
            ->set('grantCategories', ['expense'])
            ->set('grantCeiling', '1000')
            ->set('grantReason', 'Prior company grant')
            ->set('companyId', $other->id)
            ->assertSet('newBookingId', '')
            ->assertSet('newReason', '')
            ->assertSet('newAmount', '')
            ->assertSet('grantAdminId', '')
            ->assertSet('grantCategories', [])
            ->assertSet('grantCeiling', '')
            ->assertSet('grantReason', '')
            ->assertSet('loadedCompanyId', $other->id);
        $this->assertSame(0, DB::table('approval_requests')->count());
        $this->assertSame(0, DB::table('approval_delegations')->count());
    }


    public function test_form_rejects_missing_proposal_and_linked_expense_before_write(): void
    {
        [$company, , $members] = $this->fixture();
        $component = Livewire::test(ApprovalCenter::class)
            ->set('newCategory', 'discount')
            ->set('newAmount', '250')->set('newCurrency', 'PKR')
            ->set('newBookingId', '')
            ->set('newReason', 'Synthetic discount without required proposal');
        $requestKey = $component->get('newRequestKey');
        $component->call('submitRequest')->assertHasErrors(['newBookingId']);
        $this->assertSame($requestKey, $component->get('newRequestKey'));

        $component->set('newCategory', 'expense')
            ->set('newBookingId', (string) \Illuminate\Support\Str::uuid())
            ->set('newReason', 'Synthetic linked expense must be refused')
            ->call('submitRequest')->assertHasErrors(['newBookingId']);
        $this->assertSame($requestKey, $component->get('newRequestKey'));
        $this->assertSame(0, DB::table('approval_requests')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame(0, DB::table('approval_alert_outbox')->count());
    }

    public function test_approval_livewire_request_key_rotates_only_after_server_success(): void
    {
        [$company, , $members] = $this->fixture();
        $this->be($members['admin'][0]);
        $component = Livewire::test(ApprovalCenter::class);
        $key = $component->get('newRequestKey');
        $this->assertTrue(\Illuminate\Support\Str::isUuid($key));
        $component->set('newCategory', 'expense')->set('newAmount', '0')
            ->set('newCurrency', 'PKR')->set('newBookingId', '')
            ->set('newReason', 'Do not create invalid expense')
            ->call('submitRequest')->assertHasErrors(['newAmount']);
        $this->assertSame($key, $component->get('newRequestKey'));
        $component->set('newAmount', '250')
            ->call('submitRequest')->assertHasNoErrors();
        $this->assertNotSame($key, $component->get('newRequestKey'));
        $this->assertSame($key, DB::table('approval_requests')
            ->where('company_id', $company->id)->value('id'));
        $this->assertSame(1, DB::table('approval_requests')->count());
    }


    public function test_owner_expense_does_not_follow_operator_into_manager_company(): void
    {
        [$company, , $members] = $this->fixture();
        $managed = Company::create(['name' => 'Manager operated venue', 'slug' => 'approval-switch-manager']);
        CompanyMembership::create([
            'company_id' => $managed->id, 'user_id' => $members['owner'][0]->id,
            'role' => 'manager',
        ]);
        $component = Livewire::test(ApprovalCenter::class)
            ->assertSet('companyId', $company->id)
            ->set('newCategory', 'expense')
            ->set('newAmount', '900')
            ->set('newCurrency', 'USD')
            ->set('newBookingId', (string) \Illuminate\Support\Str::uuid())
            ->set('newReason', 'Unsent Company A expense')
            ->set('grantCategories', ['expense']);
        $oldRequestKey = $component->get('newRequestKey');

        $component->set('companyId', $managed->id)
            ->assertSet('newCategory', 'discount')
            ->assertSet('newAmount', '')
            ->assertSet('newCurrency', '')
            ->assertSet('newBookingId', '')
            ->assertSet('newReason', '')
            ->assertSet('grantCategories', [])
            ->assertSet('loadedCompanyId', $managed->id);
        $this->assertNotSame($oldRequestKey, $component->get('newRequestKey'));
        $this->assertNotContains('expense', $component->instance()->requestCategoryOptions());

        // A modified Livewire payload cannot submit a company-wide expense as Manager.
        $component->set('newCategory', 'expense')
            ->set('newAmount', '500')->set('newCurrency', 'PKR')
            ->set('newReason', 'Unauthorized manager expense')
            ->call('submitRequest')->assertHasErrors(['newCategory']);
        $this->assertSame(0, DB::table('approval_requests')->count());
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame(0, DB::table('approval_delegations')->count());
        $this->assertSame(0, DB::table('approval_alert_outbox')->count());
    }

    public function test_same_company_role_downgrade_rejects_stale_owner_expense_submission(): void
    {
        [$company, , $members] = $this->fixture();
        $component = Livewire::test(ApprovalCenter::class)
            ->set('newCategory', 'expense')
            ->set('newAmount', '500')->set('newCurrency', 'PKR')
            ->set('newReason', 'Previously eligible company expense');
        $members['owner'][1]->update(['role' => 'manager']);
        $component->call('submitRequest')->assertHasErrors(['newCategory']);
        $this->assertNotContains('expense', $component->instance()->requestCategoryOptions());
        $this->assertSame(0, DB::table('approval_requests')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame(0, DB::table('approval_alert_outbox')->count());
    }

    public function test_manager_request_form_excludes_company_wide_expense_category(): void
    {
        [, , $members] = $this->fixture();
        $this->be($members['manager'][0]);
        $component = Livewire::test(ApprovalCenter::class);
        $this->assertNotContains('expense', $component->instance()->requestCategoryOptions());
        $this->assertContains('discount', $component->instance()->requestCategoryOptions());
        $this->be($members['owner'][0]);
        $this->assertContains('expense', Livewire::test(ApprovalCenter::class)
            ->instance()->requestCategoryOptions());
        $this->assertSame(0, DB::table('approval_requests')->count());
    }

    public function test_manager_cannot_view_other_staff_approval_or_other_company(): void
    {
        [$company, $foreign, $members] = $this->fixture();
        $admin = $members['admin'][0];
        $this->be($admin);
        Livewire::test(ApprovalCenter::class)
            ->set('newCategory', 'expense')->set('newAmount', '300')
            ->set('newCurrency', 'PKR')->set('newBookingId', '')
            ->set('newReason', 'Private administrative request')
            ->call('submitRequest')->assertHasNoErrors();

        $this->be($members['manager'][0]);
        Livewire::test(ApprovalCenter::class)
            ->assertDontSee('Private administrative request')
            ->assertDontSee('Emergency delegation')
            ->set('companyId', $foreign->id)->assertForbidden();
        $this->assertSame(1, DB::table('approval_requests')->where('company_id', $company->id)->count());
    }


    public function test_stale_authenticated_owner_loses_panel_access_after_persisted_account_revocation(): void
    {
        [, , $members] = $this->fixture();
        $owner = $members['owner'][0];
        $component = Livewire::test(ApprovalCenter::class)
            ->assertSee('Request approval');
        // Keep the auth model in memory; revoke only the persisted database account.
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active);
        $this->assertSame([], $component->instance()->companyOptions());
        $this->assertSame('', $component->instance()->role());
        $component->call('refreshInbox')->assertForbidden();
        Livewire::test(ApprovalCenter::class)->assertForbidden();

        // Restore eligibility and mount a NEW authorized component so the
        // second case independently tests revocation during a live session.
        DB::table('users')->where('id', $owner->id)->update(['is_active' => true]);
        $verifiedComponent = Livewire::test(ApprovalCenter::class)
            ->assertSee('Request approval');
        DB::table('users')->where('id', $owner->id)
            ->update(['email_verified_at' => null]);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse(ApprovalCenter::canAccess());
        $verifiedComponent->call('refreshInbox')->assertForbidden();
        Livewire::test(ApprovalCenter::class)->assertForbidden();
        $this->assertSame(0, DB::table('approval_requests')->count());
        $this->assertSame(0, DB::table('approval_decisions')->count());
        $this->assertSame(0, DB::table('approval_alert_outbox')->count());
    }

    public function test_revoked_or_unverified_member_cannot_use_livewire_actions(): void
    {
        [, , $members] = $this->fixture();
        $component = Livewire::test(ApprovalCenter::class);
        $members['owner'][1]->update(['is_active' => false]);
        $component->call('refreshInbox')->assertForbidden();
        Livewire::test(ApprovalCenter::class)->assertForbidden();
        $members['owner'][1]->update(['is_active' => true]);
        $members['owner'][0]->forceFill(['email_verified_at' => null])->save();
        Livewire::test(ApprovalCenter::class)->assertForbidden();
    }
    public function test_owner_delegations_are_fresh_authorized_and_hide_expired_or_revoked_grants(): void
    {
        [$company, $foreign, $members] = $this->fixture();
        $owner = $members['owner'][0];
        $admin = $members['admin'][0];
        $service = app(\App\Services\ApprovalWorkflowService::class);
        $active = (string) \Illuminate\Support\Str::uuid();
        $expired = (string) \Illuminate\Support\Str::uuid();
        $service->grant($owner, $company->id, $active, $admin->id, ['discount'], 1000,
            now()->toIso8601String(), now()->addHours(2)->toIso8601String(),
            'Owner limited exception authority');
        $service->grant($owner, $company->id, $expired, $admin->id, ['expense'], 250,
            now()->toIso8601String(), now()->addHour()->toIso8601String(),
            'Second restricted trial grant');
        // Advance the real test clock past the earlier grant's legitimate
        // expiry; do not violate PostgreSQL chronological CHECK constraints.
        \Carbon\Carbon::setTestNow(now()->addMinutes(65));
        try {
            $current = $service->activeDelegations($owner, $company->id);
        $this->assertCount(1, $current);
        $this->assertSame($active, $current[0]['id']);
        $this->assertSame(['discount'], $current[0]['categories']);
        $this->assertSame($owner->id, (int) $current[0]['granted_by']);
        $this->assertSame('Owner limited exception authority', $current[0]['reason']);
        Livewire::test(ApprovalCenter::class)->assertSee('Active / scheduled grants')
            ->assertSee('Admin #'.$admin->id)
            ->assertSee('Allowed: discount')
            ->assertSee('Granted by staff #'.$owner->id)
            ->assertSee('Grant reason: Owner limited exception authority');
        $this->be($admin);
        try {
            $service->activeDelegations($admin, $company->id);
            $this->fail('Admin viewed owner emergency grants.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($owner);
        try {
            $service->activeDelegations($owner, $foreign->id);
            $this->fail('Foreign owner emergency grants leaked.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }
            $service->revoke($owner, $company->id, $active, 'Remove delegated administrator access');
            $this->assertSame([], $service->activeDelegations($owner, $company->id));
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_pending_company_link_enforces_membership_before_loading_inbox(): void
    {
        [$company, $foreign, $members] = $this->fixture();
        $owner = $members['owner'][0];
        $this->be($owner);
        $secondary = Company::create(['name' => 'Other owned venue', 'slug' => 'approval-ui-owned']);
        CompanyMembership::create(['company_id' => $secondary->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        // Livewire boots each test with its own synthetic HTTP request. Use
        // its URL-query harness, not a different global Laravel request object.
        Livewire::withQueryParams(['company' => $secondary->id])
            ->test(ApprovalCenter::class)->assertSet('companyId', $secondary->id);
        Livewire::withQueryParams(['company' => $foreign->id])
            ->test(ApprovalCenter::class)->assertForbidden();
    }

    public function test_only_owner_can_generate_private_telegram_pairing_link(): void
    {
        [$company, , $members] = $this->fixture();
        config()->set('approval_alerts.enabled', true);
        config()->set('approval_alerts.bot_token', '123456:synthetic_telegram_bot_token_123456789012345');
        config()->set('approval_alerts.webhook_secret', 'synthetic_private_webhook_token_123456789012');
        config()->set('approval_alerts.bot_username', 'VenueDeskTestBot');
        Livewire::test(ApprovalCenter::class)
            ->call('beginTelegramPairing')->assertHasNoErrors()
            ->assertSee('https://t.me/VenueDeskTestBot?start=VD', false);
        $this->assertSame(1, DB::table('telegram_pair_challenges')->where('company_id', $company->id)->count());

        $this->be($members['admin'][0]);
        Livewire::test(ApprovalCenter::class)->call('beginTelegramPairing')->assertForbidden();
        $this->assertSame(1, DB::table('telegram_pair_challenges')->count());
    }

    public function test_owner_reads_approved_private_decision_history_without_reapplying(): void
    {
        [$company, , $members] = $this->fixture();
        $admin = $members['admin'][0];
        $owner = $members['owner'][0];
        $service = app(\App\Services\ApprovalWorkflowService::class);
        $this->be($admin);
        $id = (string) \Illuminate\Support\Str::uuid();
        $service->request($admin, $company->id, $id, 'expense', 650,
            'PKR', null, 'Synthetic staff supplies expense');
        $this->be($owner);
        $service->decide($owner, $company->id, $id, 1, 'approved',
            'Owner accepted proposed supplies');
        Livewire::test(ApprovalCenter::class)
            ->set('statusFilter', 'approved')
            ->assertSee('Recorded approval decision')
            ->assertSee('Decision: approved by')
            ->assertSee('Owner accepted proposed supplies')
            ->assertSee('Synthetic staff supplies expense');
        $this->assertSame(1, DB::table('approval_decisions')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

}
