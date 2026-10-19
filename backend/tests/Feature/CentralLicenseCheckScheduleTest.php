<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use App\Services\CentralLicenseCheckSchedule;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CentralLicenseCheckScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_check_fifteen_day_cadence_manual_verify_and_bounded_outage_retry(): void
    {
        config()->set('central_control.activation_enabled', true);
        app(CentralInstallationIdentity::class)->initialize();
        $service=app(CentralLicenseCheckSchedule::class);
        $first=new DateTimeImmutable('2026-10-01T00:00:00Z');
        $this->assertTrue($service->isDue($first));
        $service->recordVerifiedSuccess($first);
        $this->assertFalse($service->isDue(new DateTimeImmutable('2026-10-15T23:59:59Z')));
        $due=new DateTimeImmutable('2026-10-16T00:00:00Z');
        $this->assertTrue($service->isDue($due));
        $service->recordFailure($due,'network');
        $this->assertFalse($service->isDue(new DateTimeImmutable('2026-10-16T00:00:30Z')));
        $this->assertTrue($service->isDue($due,true), 'manual verify may be tried anytime if separately rate-limited');
        $this->assertTrue($service->isDue(new DateTimeImmutable('2026-10-16T00:01:00Z')));
        $this->assertSame(1,(int)DB::table('central_license_check_state')->value('consecutive_failures'));
        $this->assertSame('2026-10-01 00:00:00+00:00',
            (new DateTimeImmutable(DB::table('central_license_check_state')
                ->value('last_online_verified_at')))->format('Y-m-d H:i:sP'));
        $service->recordVerifiedSuccess(new DateTimeImmutable('2026-10-16T00:05:00Z'));
        $this->assertFalse($service->isDue(new DateTimeImmutable('2026-10-16T00:10:00Z')));
        $this->assertSame(0,(int)DB::table('central_license_check_state')->value('consecutive_failures'));
        try {
            $service->recordVerifiedSuccess(new DateTimeImmutable('2026-10-15T00:00:00Z'));
            $this->fail('An old central verification time overwrote newer success.');
        } catch (\InvalidArgumentException) {}
        $this->assertSame('2026-10-16T00:05:00Z',
            (new DateTimeImmutable(DB::table('central_license_check_state')
                ->value('last_online_verified_at')))->format('Y-m-d\TH:i:s\Z'));
        $this->assertTrue($service->isDue(new DateTimeImmutable('2026-10-10T00:00:00Z')),
            'Clock rollback must not prevent contacting central.');

    }

    public function test_clock_rollback_still_honors_backoff_after_first_forced_check(): void
    {
        config()->set('central_control.activation_enabled',true);
        app(CentralInstallationIdentity::class)->initialize();
        $service=app(CentralLicenseCheckSchedule::class);
        $service->recordVerifiedSuccess(new DateTimeImmutable('2026-10-01T00:00:00Z'));
        $service->recordFailure(new DateTimeImmutable('2026-10-16T00:00:00Z'),'network');
        $rolledBack=new DateTimeImmutable('2026-09-30T00:00:00Z');
        $this->assertTrue($service->isDue($rolledBack),
            'First rollback bypasses a stale future retry.');
        $service->recordFailure($rolledBack,'network');
        $this->assertFalse($service->isDue($rolledBack->modify('+30 seconds')),
            'Repeated rollback must not bypass retry backoff.');
        $this->assertTrue($service->isDue($rolledBack->modify('+2 minutes')),
            'Due check resumes after bounded retry interval.');
    }

    public function test_disabled_installation_never_checks_or_mutates_tracking_state(): void
    {
        config()->set('central_control.activation_enabled', false);
        $service=app(CentralLicenseCheckSchedule::class);
        $this->assertFalse($service->isDue(new DateTimeImmutable('2026-10-17T00:00:00Z')));
        $this->assertDatabaseCount('central_license_check_state',0);
    }
}
