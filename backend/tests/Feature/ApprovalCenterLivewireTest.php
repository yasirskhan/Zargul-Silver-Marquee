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
            ->call('selectRequest', $approval->id)
            ->set('decisionReason', 'Owner reviewed proposed expense')
            ->call('approve')->assertHasNoErrors()
            ->assertSee('approved');
        $this->assertSame('approved', DB::table('approval_requests')->where('id', $approval->id)->value('status'));
        $this->assertSame(1, DB::table('approval_decisions')->count());
        $this->assertSame(2, DB::table('approval_alert_outbox')->count());
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
}
