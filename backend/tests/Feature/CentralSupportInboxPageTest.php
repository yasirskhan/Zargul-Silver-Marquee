<?php

namespace Tests\Feature;

use App\Filament\Pages\SupportInbox;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralInstallationCompanyScope;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CentralSupportInboxPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_bound_owner_only_sees_local_replies_and_foreign_owner_denied():void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Local Support Venue','slug'=>'local-support']);
        $foreign=Company::create(['name'=>'Foreign Support Venue','slug'=>'foreign-support']);
        $owner=User::create(['name'=>'Local Owner','email'=>'local-inbox@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        $other=User::create(['name'=>'Other Owner','email'=>'other-inbox@example.test','password'=>'test-only']);
        $other->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        CompanyMembership::create(['company_id'=>$foreign->id,'user_id'=>$other->id,'role'=>'owner']);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>(string)Str::uuid(),
            'approved_domain'=>'support-page.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('filament.admin.pages.hafzatech-support'));
        $view=Livewire::test(SupportInbox::class)
            ->assertSet('companyId',$company->id)
            ->assertSee('HAFZATECH Support')
            ->assertSee('0 unread replies')
            ->assertDontSee($foreign->name);
        $view->call('markMessageRead','invalid-uuid')->assertStatus(422);
        DB::table('company_memberships')
            ->where('company_id',$company->id)->where('user_id',$owner->id)
            ->update(['role'=>'manager']);
        // Filament also hides an already-mounted page when the user's role is revoked.
        $view->call('refreshMessages')->assertNotFound();
        $this->be($other);
        $this->assertFalse(SupportInbox::canAccess());
        // Fresh Livewire mount aborts 403, unlike revoked mounted-page hydration (404).
        Livewire::test(SupportInbox::class)->assertForbidden();
        $this->assertDatabaseCount('finance_journals',0);
    }
}
