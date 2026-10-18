<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralSupportOutboxService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CentralSupportOutboxServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Support Venue','slug'=>'support-venue']);
        $owner=User::create(['name'=>'Support Owner','email'=>'support-owner@example.test',
            'password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'support.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        return [$company,$owner];
    }

    public function test_support_gate_off_prevents_queueing_or_network_calls(): void
    {
        [$company,$owner]=$this->fixture();
        config()->set('central_control.support_enabled',false);
        Http::preventStrayRequests();
        try {
            app(CentralSupportOutboxService::class)->queue($owner,$company->id,
                (string)Str::uuid(),'Support question','Please help with a general setup question.');
            $this->fail('Support release gate bypassed.');
        }catch(AuthorizationException){}
        $this->assertDatabaseCount('central_support_outbox',0);
        Http::assertNothingSent();
    }

    public function test_outbox_is_encrypted_local_idempotent_and_company_scoped(): void
    {
        [$company,$owner]=$this->fixture();
        config()->set('central_control.support_enabled',true);
        config()->set('central_control.activation_enabled',false);
        Http::preventStrayRequests();
        $service=app(CentralSupportOutboxService::class);
        $request=(string)Str::uuid();
        $subject='Help with setup';
        $message='How do I configure hall hours?';
        $first=$service->queue($owner,$company->id,$request,$subject,$message);
        $this->assertSame('queued',$first['state']);
        $this->assertSame($first,$service->queue($owner,$company->id,$request,$subject,$message));
        $this->assertDatabaseCount('central_support_outbox',1);
        $stored=DB::table('central_support_outbox')->first();
        $this->assertStringNotContainsString($message,$stored->encrypted_body);
        $this->assertStringNotContainsString($subject,$stored->encrypted_subject);
        $this->assertSame($message,Crypt::decryptString($stored->encrypted_body));
        try {
            $service->queue($owner,$company->id,$request,$subject,'Different request contents');
            $this->fail('Idempotency key reused for changed ticket.');
        }catch(InvalidArgumentException){}
        $other=Company::create(['name'=>'Foreign venue','slug'=>'other-support']);
        try {
            $service->queue($owner,$other->id,(string)Str::uuid(),
                $subject,$message);
            $this->fail('Foreign company ticket accepted.');
        }catch(AuthorizationException){}
        $this->assertDatabaseCount('central_support_outbox',1);
        $this->assertDatabaseCount('finance_journals',0);
        Http::assertNothingSent();
    }
}
