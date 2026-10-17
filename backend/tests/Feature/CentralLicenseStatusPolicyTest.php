<?php

namespace Tests\Feature;

use App\Services\CentralLicenseStatusPolicy;
use DateTimeImmutable;
use Tests\TestCase;

class CentralLicenseStatusPolicyTest extends TestCase
{
    private function grant(bool $isActive): array
    {
        return ['grant'=>[
            'not_before'=>'2026-10-01T00:00:00Z',
            'expires_at'=>'2027-10-01T00:00:00Z',
        ], 'active'=>$isActive];
    }

    public function test_verification_cadence_is_exactly_fifteen_days_and_outages_do_not_falsify_success(): void
    {
        $service=app(CentralLicenseStatusPolicy::class);
        $now=new DateTimeImmutable('2026-10-20T05:30:00-04:00');
        $last=new DateTimeImmutable('2026-10-01T09:30:00Z');
        $this->assertSame('2026-10-16T09:30:00Z',
            $service->nextCentralVerification($last,$now)->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-10-20T09:30:00Z',
            $service->nextCentralVerification(null,$now)->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-10-16T09:30:00Z',
            $service->nextCentralVerification($last,$now)->format('Y-m-d\TH:i:s\Z'),
            'Unavailable network never updates last successful central verification');
    }

    public function test_fifteen_day_owner_expiry_warning_immediate_public_maintenance_and_seven_day_backend_grace(): void
    {
        $service=app(CentralLicenseStatusPolicy::class);
        $before=$service->state($this->grant(true),
            new DateTimeImmutable('2027-09-15T23:59:59Z'));
        $this->assertSame('open',$before['public']);
        $this->assertFalse($before['expiry_warning']);
        $warning=$service->state($this->grant(true),
            new DateTimeImmutable('2027-09-16T00:00:00Z'));
        $this->assertTrue($warning['expiry_warning']);
        $this->assertSame('operational',$warning['dashboard']);
        $expired=$service->state($this->grant(false),
            new DateTimeImmutable('2027-10-01T00:00:00Z'));
        $this->assertSame('maintenance',$expired['public']);
        $this->assertSame('grace',$expired['dashboard']);
        $almost=$service->state($this->grant(false),
            new DateTimeImmutable('2027-10-07T23:59:59Z'));
        $this->assertSame('grace',$almost['dashboard']);
        $restricted=$service->state($this->grant(false),
            new DateTimeImmutable('2027-10-08T00:00:00Z'));
        $this->assertSame('maintenance',$restricted['public']);
        $this->assertSame('restricted',$restricted['dashboard']);
    }

    public function test_new_or_not_yet_valid_installation_never_opens_public_site(): void
    {
        $state=app(CentralLicenseStatusPolicy::class)->state($this->grant(false),
            new DateTimeImmutable('2026-09-30T23:59:59Z'));
        $this->assertSame('maintenance',$state['public']);
        $this->assertSame('restricted',$state['dashboard']);
    }
}
