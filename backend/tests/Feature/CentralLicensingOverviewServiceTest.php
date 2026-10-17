<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use App\Services\CentralLicensingOverviewService;
use App\Services\CentralSignedLicenseCache;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralLicensingOverviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
    }

    public function test_default_off_never_restricts_existing_public_or_dashboard_routes(): void
    {
        config()->set('central_control.activation_enabled',false);
        $before=DB::table('central_license_check_state')->count();
        $view=app(CentralLicensingOverviewService::class)
            ->snapshot(new DateTimeImmutable('2026-10-17T00:00:00Z'));
        $this->assertSame('feature_disabled',$view['state']);
        $this->assertSame('unchanged',$view['public_policy']);
        $this->assertSame('unchanged',$view['dashboard_policy']);
        $this->assertFalse($view['online_check_due']);
        $this->assertSame($before,DB::table('central_license_check_state')->count());
    }

    public function test_enrolled_without_paid_grant_is_not_marked_licensed(): void
    {
        config()->set('central_control.activation_enabled',true);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        $customer=(string) Str::uuid();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>$customer,'approved_domain'=>'hall.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $state=app(CentralLicensingOverviewService::class)
            ->snapshot(new DateTimeImmutable('2026-10-17T00:00:00Z'));
        $this->assertSame('awaiting_signed_license',$state['state']);
        $this->assertSame('maintenance',$state['public_policy']);
        $this->assertSame('restricted',$state['dashboard_policy']);
        $this->assertTrue($state['online_check_due']);
        $this->assertDatabaseCount('central_license_check_state',0);
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_locally_verified_license_warning_and_post_expiry_grace_are_read_only(): void
    {
        config()->set('central_control.activation_enabled',true);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        $customer=(string)Str::uuid();
        $license=(string)Str::uuid();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>$customer,'approved_domain'=>'hall.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $pair=sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys',[
            'test-key'=>self::b64(sodium_crypto_sign_publickey($pair)),
        ]);
        $grant=[
            'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
            'license_id'=>$license,'installation_id'=>$identity['installation_id'],
            'customer_id'=>$customer,'revision'=>2,'term_months'=>12,
            'edition'=>'standard','approved_domains'=>['hall.example.test'],
            'not_before'=>'2026-10-01T00:00:00Z','expires_at'=>'2027-10-01T00:00:00Z',
            'limits'=>['max_halls'=>2,'max_staff'=>10],
            'features'=>['booking'=>true],
        ];
        $raw=json_encode($grant,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $envelope=[
            'key_id'=>'test-key','payload'=>self::b64($raw),
            'signature'=>self::b64(sodium_crypto_sign_detached(
                'HAFZATECH-LICENSE-GRANT-V1'."\n".$raw,
                sodium_crypto_sign_secretkey($pair))),
        ];
        app(CentralSignedLicenseCache::class)->accept($envelope,
            $customer,'hall.example.test',new DateTimeImmutable('2026-11-01T00:00:00Z'));
        $overview=app(CentralLicensingOverviewService::class);
        $warning=$overview->snapshot(new DateTimeImmutable('2027-09-16T00:00:00Z'));
        $this->assertSame('licensed',$warning['state']);
        $this->assertTrue($warning['expiry_warning']);
        $this->assertSame(2,$warning['revision']);
        $this->assertSame('open',$warning['public_policy']);
        $expired=$overview->snapshot(new DateTimeImmutable('2027-10-01T00:00:00Z'));
        $this->assertSame('expired_grace',$expired['state']);
        $this->assertSame('maintenance',$expired['public_policy']);
        $this->assertSame('grace',$expired['dashboard_policy']);
        $after=$overview->snapshot(new DateTimeImmutable('2027-10-08T00:00:00Z'));
        $this->assertSame('restricted',$after['dashboard_policy']);
        $this->assertDatabaseCount('central_license_check_state',0);
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_missing_enrollment_binding_is_restricted_only_when_commercial_gate_is_enabled(): void
    {
        config()->set('central_control.activation_enabled',true);
        $view=app(CentralLicensingOverviewService::class)
            ->snapshot(new DateTimeImmutable('2026-10-17T00:00:00Z'));
        $this->assertSame('enrollment_required',$view['state']);
        $this->assertSame('maintenance',$view['public_policy']);
    }
}
