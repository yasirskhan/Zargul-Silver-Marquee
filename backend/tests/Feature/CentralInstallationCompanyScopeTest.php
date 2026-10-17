<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralInstallationIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralInstallationCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        $user=User::create(['name'=>$email,'email'=>$email,'password'=>'synthetic-fixture-only']);
        $user->markEmailAsVerified();
        return $user;
    }

    private function company(string $slug): Company
    {
        return Company::create(['name'=>$slug,'slug'=>$slug]);
    }

    private function member(User $actor,Company $company,string $role): void
    {
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$actor->id,
            'role'=>$role,
        ]);
    }

    public function test_only_verified_owner_can_bind_and_company_binding_is_immutable(): void
    {
        config()->set('central_control.activation_enabled',true);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        $a=$this->company('venue-a');
        $b=$this->company('venue-b');
        $ownerA=$this->user('owner-a@example.test');
        $ownerB=$this->user('owner-b@example.test');
        $managerA=$this->user('manager-a@example.test');
        $this->member($ownerA,$a,'owner');
        $this->member($ownerB,$b,'owner');
        $this->member($managerA,$a,'manager');
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>(string)Str::uuid(),
            'approved_domain'=>'venue-a.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $scope=app(CentralInstallationCompanyScope::class);
        try{
            $scope->bind($managerA,$a->id);
            $this->fail('Venue manager usurped central company license binding.');
        }catch(AuthorizationException){}
        $result=$scope->bind($ownerA,$a->id);
        $this->assertSame($a->id,$result['company_id']);
        $this->assertSame($result,$scope->bind($ownerA,$a->id));
        $scope->assertCanRead($ownerA,$a->id);
        try{
            $scope->assertCanRead($ownerB,$b->id);
            $this->fail('Foreign company owner read central license.');
        }catch(AuthorizationException){}
        try{
            $scope->bind($ownerB,$b->id);
            $this->fail('Foreign owner transferred license.');
        }catch(AuthorizationException){}
        DB::table('users')->where('id',$ownerA->id)->update(['is_active'=>false]);
        try{
            $scope->assertCanRead($ownerA,$a->id);
            $this->fail('Revoked owner session still viewed license.');
        }catch(AuthorizationException){}
        $this->assertDatabaseCount('central_installation_activation',1);
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_default_off_does_not_allow_registration_binding(): void
    {
        $company=$this->company('not-active');
        $owner=$this->user('disabled-owner@example.test');
        $this->member($owner,$company,'owner');
        config()->set('central_control.activation_enabled',false);
        $this->expectException(AuthorizationException::class);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
    }
}
