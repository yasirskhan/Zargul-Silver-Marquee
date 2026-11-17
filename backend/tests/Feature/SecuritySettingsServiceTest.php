<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SecuritySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecuritySettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_and_per_user_policy_and_device_revocation():void
    {
        $company=Company::create(['name'=>'Security Firm','slug'=>'security-firm']);
        $owner=User::create(['name'=>'Security Owner','email'=>'security-owner@example.test','password'=>'test-only']);
        $staff=User::create(['name'=>'Security Staff','email'=>'security-staff@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$staff,'manager']] as [$user,$role]){
            $user->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$user->id,'role'=>$role]);
        }
        $this->be($owner);
        $service=app(SecuritySettingsService::class);
        $initial=$service->state($owner,$company->id);
        $this->assertTrue($initial['mfaRequired']);
        $saved=$service->save($owner,$company->id,false,14,$initial['revision']);
        $this->assertSame(1,$saved['revision']);
        $this->assertFalse($service->requiresMfa($owner,$company->id));
        $service->setUserMfa($owner,$company->id,$staff->id,true);
        $this->assertTrue($service->requiresMfa($staff->fresh(),$company->id));
        $service->setUserMfa($owner,$company->id,$staff->id,false);
        $this->assertFalse($service->requiresMfa($staff->fresh(),$company->id));
        $service->trustCurrentDevice($owner);
        $raw=Cookie::queued(SecuritySettingsService::COOKIE)->getValue();
        $request=Request::create('/');$request->cookies->set(SecuritySettingsService::COOKIE,$raw);
        $this->assertTrue($service->isTrusted($owner->fresh(),$company->id,$request));
        $other=Company::create(['name'=>'Other MFA Company','slug'=>'other-mfa-company']);
        $this->assertFalse($service->isTrusted($owner->fresh(),$other->id,$request));
        $device=DB::table('trusted_devices')->where('user_id',$owner->id)->first();
        $this->assertNotNull($device);
        $this->assertNull($device->revoked_at);
        $this->assertCount(1,$service->devices($owner,$company->id));
    }

    public function test_mfa_policy_is_isolated_per_company():void
    {
        $first=Company::create(['name'=>'First MFA Company','slug'=>'first-mfa-company']);
        $second=Company::create(['name'=>'Second MFA Company','slug'=>'second-mfa-company']);
        $owner=User::create(['name'=>'Multi Owner','email'=>'multi-owner@example.test','password'=>'test-only']);
        $staff=User::create(['name'=>'Multi Staff','email'=>'multi-staff@example.test','password'=>'test-only']);
        foreach([$first,$second] as $company){
            $owner->markEmailAsVerified();$staff->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$staff->id,'role'=>'manager']);
        }
        $this->be($owner);
        $service=app(SecuritySettingsService::class);
        $service->save($owner,$first->id,true,30,0);
        $service->save($owner,$second->id,true,30,0);
        $service->setUserMfa($owner,$first->id,$staff->id,false);
        $this->assertFalse($service->requiresMfa($staff->fresh(),$first->id));
        $this->assertTrue($service->requiresMfa($staff->fresh(),$second->id));
        $service->setUserMfa($owner,$second->id,$staff->id,true);
        $this->assertTrue($service->requiresMfa($staff->fresh(),$second->id));
    }
}
