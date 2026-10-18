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

    public function test_fifteen_day_due_check_accepts_fresh_signed_same_revision_without_reissuing_license(): void
    {
        $this->enrolled();
        config()->set('central_control.scheduled_checks_enabled',true);
        $identity=DB::table('central_installation_identity')->first();
        $binding=DB::table('central_installation_activation')->first();
        $customer=$binding->customer_id;
        $vendor=sodium_crypto_sign_keypair();
        $encode=static fn(string $raw): string=>
            rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
        config()->set('central_control.trusted_signing_public_keys',[
            'scheduled-vendor'=>$encode(sodium_crypto_sign_publickey($vendor)),
        ]);
        $sign=static function(array $payload,string $context)use($vendor,$encode): array {
            $raw=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            return ['key_id'=>'scheduled-vendor','payload'=>$encode($raw),
                'signature'=>$encode(sodium_crypto_sign_detached(
                    $context."\n".$raw,sodium_crypto_sign_secretkey($vendor)))];
        };
        $initial=new DateTimeImmutable('2026-10-01T00:00:00Z');
        $nonce=$encode(random_bytes(32));
        $grant=[
            'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
            'license_id'=>(string)Str::uuid(), 'customer_id'=>$customer,
            'installation_id'=>$identity->installation_id,'revision'=>1,
            'term_months'=>12,'edition'=>'standard',
            'approved_domains'=>[$binding->approved_domain],
            'not_before'=>'2026-01-01T00:00:00Z','expires_at'=>'2027-01-01T00:00:00Z',
            'limits'=>['max_halls'=>1,'max_staff'=>8],
            'features'=>['booking'=>true],
        ];
        $firstReceipt=[
            'api_version'=>'v1','issuer'=>'HAFZATECH',
            'installation_id'=>$identity->installation_id,
            'customer_id'=>$customer,'nonce'=>$nonce,
            'status'=>'signed_revision_pending_delivery','revision'=>1,
            'issued_at'=>'2026-10-01T00:00:00Z',
        ];
        app(\App\Services\CentralVerifiedLicenseDeliveryService::class)->accept(
            $sign($grant,'HAFZATECH-LICENSE-GRANT-V1'),
            $sign($firstReceipt,'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'),
            $customer,$binding->approved_domain,$nonce,$initial
        );
        $originalHash=DB::table('central_signed_license_cache')->value('envelope_sha256');
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status'=>
            function($request)use($identity,$customer,$sign) {
                $receipt=[
                    'api_version'=>'v1','issuer'=>'HAFZATECH',
                    'installation_id'=>$identity->installation_id,
                    'customer_id'=>$customer,'nonce'=>$request['nonce'],
                    'status'=>'signed_revision_pending_delivery','revision'=>1,
                    'issued_at'=>$request['request_time'],
                ];
                return Http::response([
                    'api_version'=>'v1',
                    'registered_installation_id'=>$identity->installation_id,
                    'nonce'=>$request['nonce'],
                    'status'=>'signed_revision_pending_delivery','revision'=>1,
                    'signed_receipt'=>$sign($receipt,'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'),
                ],200);
            },
        ]);
        $beforeDue=new DateTimeImmutable('2026-10-15T23:59:59Z');
        $this->assertSame(['state'=>'not_due'],
            app(CentralScheduledLicenseCheckService::class)->run($beforeDue));
        Http::assertNothingSent();
        $due=new DateTimeImmutable('2026-10-16T00:00:00Z');
        $this->assertSame(['state'=>'signed_license_verified'],
            app(CentralScheduledLicenseCheckService::class)->run($due));
        $this->assertSame($originalHash,
            DB::table('central_signed_license_cache')->value('envelope_sha256'));
        $this->assertDatabaseCount('central_signed_license_cache',1);
        $this->assertSame('2026-10-16T00:00:00Z',
            (new DateTimeImmutable(DB::table('central_license_check_state')
                ->value('last_online_verified_at')))->format('Y-m-d\TH:i:s\Z'));
        $this->assertDatabaseCount('finance_journals',0);
        $this->assertSame(['state'=>'not_due'],
            app(CentralScheduledLicenseCheckService::class)->run($due));
        Http::assertSentCount(1);
    }

    public function test_opted_in_command_still_does_not_call_vendor_when_not_provisioned(): void
    {
        Http::preventStrayRequests();
        $this->assertSame(0,Artisan::call('venuedesk:check-central-license'));
        Http::assertNothingSent();
    }
}
