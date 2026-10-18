<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralOwnerInstallationOnboarding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralOwnerInstallationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function principal(string $email, bool $verified=true): User
    {
        $user=User::create([
            'name'=>$email,'email'=>$email,'password'=>'synthetic-owner-enrollment'
        ]);
        if ($verified) $user->markEmailAsVerified();
        return $user;
    }

    private function member(User $user, Company $company, string $role): void
    {
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$user->id,'role'=>$role
        ]);
    }

    public function test_disabled_and_unverified_or_non_owner_enrollment_never_contacts_vendor(): void
    {
        Http::preventStrayRequests();
        $company=Company::create(['name'=>'Test Venue','slug'=>'onboard-a']);
        $owner=$this->principal('onboard-owner@example.test');
        $manager=$this->principal('onboard-manager@example.test');
        $unverified=$this->principal('onboard-unverified@example.test',false);
        $this->member($owner,$company,'owner');
        $this->member($manager,$company,'manager');
        $this->member($unverified,$company,'owner');
        $onboard=app(CentralOwnerInstallationOnboarding::class);
        $enrollment=(string)Str::uuid();
        $customer=(string)Str::uuid();
        config()->set('central_control.activation_enabled',false);
        foreach ([$owner,$manager,$unverified] as $actor) {
            try {
                $onboard->enroll($actor,$company->id,$enrollment,'unused',$customer,'venue.example.test');
                $this->fail('Disabled installation enrollment accepted user.');
            } catch (AuthorizationException) {}
        }
        config()->set('central_control.activation_enabled',true);
        foreach ([$manager,$unverified] as $actor) {
            try {
                $onboard->enroll($actor,$company->id,$enrollment,'unused',$customer,'venue.example.test');
                $this->fail('Non-owner accessed installation vendor transport.');
            } catch (AuthorizationException) {}
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('central_installation_identity',0);
        $this->assertDatabaseCount('central_installation_activation',0);
    }

    public function test_owner_can_bind_verified_vendor_enrollment_once_without_cross_company_transfer(): void
    {
        config()->set('central_control.activation_enabled',true);
        $a=Company::create(['name'=>'Company A','slug'=>'owner-enroll-a']);
        $b=Company::create(['name'=>'Company B','slug'=>'owner-enroll-b']);
        $ownerA=$this->principal('owner-enroll-a@example.test');
        $ownerB=$this->principal('owner-enroll-b@example.test');
        $this->member($ownerA,$a,'owner');
        $this->member($ownerB,$b,'owner');
        $enrollment=(string)Str::uuid();
        $customer=(string)Str::uuid();
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $nonce=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        Http::fake([
            'https://lichall.hafzatech.com/api/v1/installations/challenge'
                =>Http::response([
                    'api_version'=>'v1','nonce'=>$nonce,'expires_in_seconds'=>300
                ],200),
            'https://lichall.hafzatech.com/api/v1/installations/register'
                =>function(Request $request)use($customer) {
                    return Http::response([
                        'api_version'=>'v1',
                        'installation_id'=>$request['installation_id'],
                        'customer_id'=>$customer,
                        'status'=>'registered_pending_license'
                    ],201);
                },
        ]);
        $onboard=app(CentralOwnerInstallationOnboarding::class);
        $result=$onboard->enroll($ownerA,$a->id,$enrollment,$token,$customer,'venue.example.test');
        $this->assertSame($a->id,$result['company_id']);
        $this->assertSame($customer,$result['customer_id']);
        $this->assertSame('registered_pending_license',$result['status']);
        $this->assertDatabaseCount('central_installation_activation',1);
        $this->assertSame($a->id,DB::table('central_installation_activation')
            ->where('singleton_id',1)->value('company_id'));
        Http::assertSentCount(2);
        $this->assertSame($result,
            $onboard->enroll($ownerA,$a->id,$enrollment,$token,$customer,'venue.example.test'));
        Http::assertSentCount(2);
        try {
            $onboard->enroll($ownerB,$b->id,$enrollment,$token,$customer,'venue.example.test');
            $this->fail('Foreign company owner took over installation.');
        } catch (AuthorizationException) {}
        Http::assertSentCount(2);
        $this->assertDatabaseCount('central_signed_license_cache',0);
        $this->assertDatabaseCount('finance_journals',0);
    }
}
