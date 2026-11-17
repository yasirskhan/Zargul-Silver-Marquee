<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SecuritySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $device=DB::table('trusted_devices')->where('user_id',$owner->id)->first();
        $this->assertNotNull($device);
        $this->assertNull($device->revoked_at);
        $this->assertCount(1,$service->devices($owner,$company->id));
        $service->revoke($owner,$company->id,$device->id);
        $this->assertNotNull(DB::table('trusted_devices')->where('id',$device->id)->value('revoked_at'));
    }
}
