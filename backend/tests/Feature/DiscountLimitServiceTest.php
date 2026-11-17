<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\DiscountLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_limits_are_saved_and_enforced():void
    {
        $company=Company::create(['name'=>'Discount Firm','slug'=>'discount-firm']);
        $owner=User::create(['name'=>'Discount Owner','email'=>'discount-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $this->be($owner);
        $service=app(DiscountLimitService::class);
        $service->save($owner,$company->id,['owner'=>2000,'admin'=>1000,'manager'=>500]);
        $this->assertSame(500,$service->limitBps($company->id,'manager'));
        $this->assertFalse($service->requiresApproval($company->id,'manager',5000,100000));
        $this->assertTrue($service->requiresApproval($company->id,'manager',5001,100000));
        $this->assertTrue($service->requiresApproval($company->id,'manager',0,100000));
    }
}
