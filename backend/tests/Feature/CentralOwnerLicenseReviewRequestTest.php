<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralOwnerLicenseReviewRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CentralOwnerLicenseReviewRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_scoped_support_review_never_issues_a_license_or_invoice():void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Review Service Hall','slug'=>'review-service-hall']);
        $foreign=Company::create(['name'=>'Foreign Service Hall','slug'=>'foreign-service-hall']);
        $owner=User::create(['name'=>'Owner','email'=>'review-service-owner@example.test',
            'password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'review-service.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        $service=app(CentralOwnerLicenseReviewRequest::class);
        try {
            $service->queue($owner,$company->id,12);
            $this->fail('Support off issued a renewal contact');
        } catch (AuthorizationException) {}
        $this->assertDatabaseCount('central_support_outbox',0);
        config()->set('central_control.support_enabled',true);
        try {
            $service->queue($owner,$foreign->id,12);
            $this->fail('Foreign company created a renewal contact');
        } catch (AuthorizationException) {}
        try {
            $service->queue($owner,$company->id,24);
            $this->fail('Unsupported subscription term');
        } catch (InvalidArgumentException) {}
        $first=$service->queue($owner,$company->id,12);
        $again=$service->queue($owner,$company->id,12);
        $this->assertSame($first,$again);
        $this->assertSame('queued',$first['state']);
        $this->assertDatabaseCount('central_support_outbox',1);
        $six=$service->queue($owner,$company->id,6);
        $this->assertNotSame($six['ticket_id'],$first['ticket_id']);
        $this->assertDatabaseCount('central_support_outbox',2);
        $this->assertDatabaseCount('finance_journals',0);
        $this->assertDatabaseCount('central_signed_license_cache',0);
        $this->assertDatabaseCount('central_license_check_state',0);
    }
}
