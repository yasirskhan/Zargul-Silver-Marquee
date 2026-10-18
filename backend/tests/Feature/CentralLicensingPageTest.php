<?php

namespace Tests\Feature;

use App\Filament\Pages\Licensing;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralInstallationIdentity;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CentralLicensingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_bound_owner_only_sees_private_read_only_license_overview(): void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Licensed Hall A','slug'=>'licensed-a']);
        $foreign=Company::create(['name'=>'Foreign Hall B','slug'=>'licensed-b']);
        $owner=User::create(['name'=>'Owner A','email'=>'license-owner@example.test',
            'password'=>'test-only']);
        $owner->markEmailAsVerified();
        $other=User::create(['name'=>'Owner B','email'=>'foreign-owner@example.test',
            'password'=>'test-only']);
        $other->markEmailAsVerified();
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        CompanyMembership::create([
            'company_id'=>$foreign->id,'user_id'=>$other->id,'role'=>'owner']);
        $installation=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$installation['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'hall-a.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        Livewire::test(Licensing::class)
            ->assertSet('companyId',$company->id)
            ->assertSet('summary.state','awaiting_signed_license')
            ->assertSee('License status')
            ->assertSee('Verify License (central service not activated)')
            ->assertDontSee($foreign->name)
            ->assertSet('summary.revision',null);
        $this->be($other);
        $this->assertFalse(Licensing::canAccess());
        Livewire::test(Licensing::class)->assertForbidden();
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_explicit_owner_verify_observes_manual_release_gate_and_shared_cooldown(): void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Manual UX Hall','slug'=>'manual-ux-hall']);
        $owner=User::create(['name'=>'Manual UX Owner',
            'email'=>'manual-ux-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $installation=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$installation['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'manual-ux.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        Http::preventStrayRequests();
        Livewire::test(Licensing::class)->call('verifyLicense')->assertForbidden();
        Http::assertNothingSent();
        config()->set('central_control.manual_checks_enabled',true);
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status'=>
            function ($request) use ($installation){
                return Http::response([
                    'api_version'=>'v1',
                    'registered_installation_id'=>$installation['installation_id'],
                    'nonce'=>$request['nonce'],'revision'=>0,
                    'status'=>'registered_pending_license',
                ],200);
            },
        ]);
        $view=Livewire::test(Licensing::class);
        $view->assertSee('Verify License')->call('verifyLicense')
            ->assertSet('verificationMessage','The central service has not delivered a valid signed license.');
        $view->call('verifyLicense')
            ->assertSet('verificationMessage','A verification was just requested. Please try again shortly.');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('central_manual_license_check_attempts',1);
        $this->assertDatabaseCount('central_signed_license_cache',0);
        $this->assertDatabaseCount('finance_journals',0);
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$owner->id)->update(['role'=>'manager']);
        $view->call('verifyLicense')->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_page_rechecks_role_revocation_before_another_livewire_call(): void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Authorized Venue','slug'=>'auth-license']);
        $owner=User::create(['name'=>'Owner','email'=>'auth-license-owner@example.test',
            'password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $installation=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$installation['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'auth.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $view=Livewire::test(Licensing::class)->assertSee('License status');
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$owner->id)->update(['role'=>'manager']);
        $view->call('refreshStatus')->assertForbidden();
    }
}
