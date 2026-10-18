<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralOwnerManualLicenseVerification;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralOwnerManualLicenseVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function setupOwner(): array
    {
        config()->set('central_control.activation_enabled', true);
        $company=Company::create(['name'=>'Manual Check Venue','slug'=>'manual-verify-venue']);
        $owner=User::create(['name'=>'Venue owner','email'=>'manual-verify-owner@example.test',
            'password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'manual.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        return [$company,$owner,$identity];
    }

    public function test_manual_gate_off_denies_even_a_company_owner_without_network_or_write(): void
    {
        [$company,$owner]=$this->setupOwner();
        Http::preventStrayRequests();
        config()->set('central_control.manual_checks_enabled',false);
        try{
            app(CentralOwnerManualLicenseVerification::class)->verify($owner,$company->id,new DateTimeImmutable('now'));
            $this->fail('Manual release gate bypassed.');
        }catch(AuthorizationException){}
        $this->assertDatabaseCount('central_manual_license_check_attempts',0);
        Http::assertNothingSent();
    }

    public function test_owner_requests_once_per_minute_and_unsigned_response_is_not_payment_or_license(): void
    {
        [$company,$owner,$identity]=$this->setupOwner();
        config()->set('central_control.manual_checks_enabled',true);
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status'=>
            function ($request) use ($identity){
                return Http::response([
                    'api_version'=>'v1','registered_installation_id'=>$identity['installation_id'],
                    'nonce'=>$request['nonce'],'status'=>'registered_pending_license','revision'=>0,
                ],200);
            },
        ]);
        $now=new DateTimeImmutable('now');
        $service=app(CentralOwnerManualLicenseVerification::class);
        $this->assertSame(['state'=>'pending_signed_license'],
            $service->verify($owner,$company->id,$now));
        $this->assertSame(['state'=>'rate_limited'],
            $service->verify($owner,$company->id,$now));
        $this->assertDatabaseCount('central_manual_license_check_attempts',1);
        $this->assertDatabaseCount('central_signed_license_cache',0);
        $this->assertDatabaseCount('central_license_check_state',0);
        $this->assertDatabaseCount('finance_journals',0);
        Http::assertSentCount(1);
        $other=Company::create(['name'=>'Different company','slug'=>'different-manual']);
        try{
            $service->verify($owner,$other->id,$now);
            $this->fail('Cross-company manual verify was accepted.');
        }catch(AuthorizationException){}
        Http::assertSentCount(1);
    }

    public function test_role_downgrade_blocks_manual_verify_even_if_release_flag_is_on(): void
    {
        [$company,$owner]=$this->setupOwner();
        config()->set('central_control.manual_checks_enabled',true);
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$owner->id)->update(['role'=>'manager']);
        Http::preventStrayRequests();
        $this->expectException(AuthorizationException::class);
        app(CentralOwnerManualLicenseVerification::class)->verify(
            $owner,$company->id,new DateTimeImmutable('now')
        );
    }
}
