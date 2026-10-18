<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use App\Services\CentralScheduledLicenseCheckService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralScheduledLicenseCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private function enrolled(): void
    {
        config()->set('central_control.activation_enabled',true);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        DB::table('companies')->insert([
            'id'=>(string)Str::uuid(),'name'=>'Synthetic Gated Venue',
            'slug'=>'synthetic-scheduled-license','is_active'=>true,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $company=DB::table('companies')->value('id');
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>(string)Str::uuid(),'company_id'=>$company,
            'approved_domain'=>'scheduled.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function test_default_off_and_missing_company_binding_never_contact_central(): void
    {
        Http::preventStrayRequests();
        $now=new DateTimeImmutable('2026-10-17T22:00:00Z');
        $this->assertSame(['state'=>'disabled'],
            app(CentralScheduledLicenseCheckService::class)->run($now));
        config()->set('central_control.activation_enabled',true);
        config()->set('central_control.scheduled_checks_enabled',true);
        $this->assertSame(['state'=>'not_enrolled'],
            app(CentralScheduledLicenseCheckService::class)->run($now));
        Http::assertNothingSent();
        $this->assertDatabaseCount('central_license_check_state',0);
    }

    public function test_no_early_network_request_and_success_is_never_fabricated_for_unsigned_status(): void
    {
        $this->enrolled();
        config()->set('central_control.scheduled_checks_enabled',true);
        $now=new DateTimeImmutable('2026-10-17T22:00:00Z');
        DB::table('central_license_check_state')->insert([
            'singleton_id'=>1,
            'installation_id'=>DB::table('central_installation_identity')->value('installation_id'),
            'last_online_verified_at'=>'2026-10-16 22:00:00+00',
            'consecutive_failures'=>0,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        Http::preventStrayRequests();
        $this->assertSame(['state'=>'not_due'],
            app(CentralScheduledLicenseCheckService::class)->run($now));
        Http::assertNothingSent();
        DB::table('central_license_check_state')->where('singleton_id',1)
            ->update(['last_online_verified_at'=>'2026-10-01 22:00:00+00']);
        $install=DB::table('central_installation_identity')->value('installation_id');
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status'=>
            function ($request) use ($install) {
                return Http::response([
                    'api_version'=>'v1','status'=>'registered_pending_license',
                    'registered_installation_id'=>$install,
                    'nonce'=>$request['nonce'],'revision'=>0,
                ],200);
            },
        ]);
        $this->assertSame(['state'=>'pending_signed_license'],
            app(CentralScheduledLicenseCheckService::class)->run($now));
        $last=new DateTimeImmutable(DB::table('central_license_check_state')
            ->value('last_online_verified_at'));
        $this->assertSame('2026-10-01T22:00:00Z',$last->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('invalid_response',
            DB::table('central_license_check_state')->value('last_failure_category'));
        $this->assertDatabaseCount('central_signed_license_cache',0);
        Http::assertSentCount(1);
        $this->assertSame(['state'=>'not_due'],
            app(CentralScheduledLicenseCheckService::class)->run($now));
        Http::assertSentCount(1);
    }

    public function test_opted_in_command_still_does_not_call_vendor_when_not_provisioned(): void
    {
        Http::preventStrayRequests();
        $this->assertSame(0,Artisan::call('venuedesk:check-central-license'));
        Http::assertNothingSent();
    }
}
