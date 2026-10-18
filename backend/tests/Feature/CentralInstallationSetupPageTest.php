<?php

namespace Tests\Feature;

use App\Filament\Pages\InstallationSetup;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CentralInstallationSetupPageTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email, Company $company, string $role = 'owner', bool $verified = true): User
    {
        $user = User::create(['name'=>$email, 'email'=>$email, 'password'=>'synthetic-only']);
        if ($verified) $user->markEmailAsVerified();
        CompanyMembership::create([
            'company_id'=>$company->id, 'user_id'=>$user->id, 'role'=>$role
        ]);
        return $user;
    }

    public function test_setup_is_off_by_default_and_denies_non_owner_and_unverified_user(): void
    {
        $company=Company::create(['name'=>'Owner Venue', 'slug'=>'owner-venue-setup']);
        $owner=$this->owner('setup-owner@example.test',$company);
        $manager=$this->owner('setup-manager@example.test',$company,'manager');
        $unverified=$this->owner('setup-unverified@example.test',$company,'owner',false);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        config()->set('central_control.activation_enabled',false);
        $this->be($owner);
        $this->assertFalse(InstallationSetup::canAccess());
        Livewire::test(InstallationSetup::class)->assertForbidden();
        config()->set('central_control.activation_enabled',true);
        foreach ([$manager,$unverified] as $user) {
            $this->be($user);
            $this->assertFalse(InstallationSetup::canAccess());
            Livewire::test(InstallationSetup::class)->assertForbidden();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('central_installation_identity',0);
    }

    public function test_verified_owner_can_register_without_creating_an_unpaid_entitlement(): void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Connected Venue','slug'=>'connected-venue-setup']);
        $actor=$this->owner('connected-setup-owner@example.test',$company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($actor);
        $customer=(string)Str::uuid();
        $enrollment=(string)Str::uuid();
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $nonce=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        Http::fake([
            'https://lichall.hafzatech.com/api/v1/installations/challenge'
                =>Http::response(['api_version'=>'v1','nonce'=>$nonce,'expires_in_seconds'=>300],200),
            'https://lichall.hafzatech.com/api/v1/installations/register'
                =>fn ($request)=>Http::response([
                    'api_version'=>'v1',
                    'installation_id'=>$request['installation_id'],
                    'customer_id'=>$customer,
                    'status'=>'registered_pending_license'
                ],201),
        ]);
        $view=Livewire::test(InstallationSetup::class)
            ->assertSee('Connect your venue to HAFZATECH')
            ->set('enrollmentId',$enrollment)
            ->set('enrollmentToken',$token)
            ->set('vendorCustomerId',$customer)
            ->set('verifiedDomain','connected.example.test')
            ->call('connect')
            ->assertSet('enrollmentToken','')
            ->assertSet('statusMessage','Installation registered. A separately verified paid license is still required.')
            ->assertDontSee($token);
        $this->assertSame($company->id,DB::table('central_installation_activation')
            ->where('singleton_id',1)->value('company_id'));
        $this->assertDatabaseCount('central_signed_license_cache',0);
        Http::assertSentCount(2);
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$actor->id)->update(['role'=>'manager']);
        $view->call('connect')->assertForbidden();
        Http::assertSentCount(2);
    }
}
