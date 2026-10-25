<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\TenantPaymentSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class TenantPaymentSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Payment Venue', 'slug' => 'payment-venue']);
        $owner = User::create(['name' => 'Owner',
            'email' => 'payment-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $this->be($owner);

        return [$company, $owner];
    }

    public function test_bank_fields_round_trip_and_revision_conflicts_fail_closed(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(TenantPaymentSettingsService::class);
        $config = [
            'banks' => [
                ['bankName' => 'Tenant Bank', 'branch' => 'Main',
                    'accountMasked' => '****1234'],
                ['bankName' => 'Second Bank', 'branch' => 'Branch',
                    'accountMasked' => 'XXXX5678'],
            ],
            'instructions' => 'Pay at the venue office',
            'qrInstructions' => 'Use the venue QR only',
            'methods' => ['bank_transfer', 'cash', 'raast'],
            'depositPercent' => 5, 'providerApiEnabled' => false,
        ];
        $saved = $service->save($owner, $company->id, $config, 0);
        $this->assertSame(1, $saved['revision']);
        $this->assertSame('Tenant Bank', $saved['config']['banks'][0]['bankName']);
        $state = $service->state($owner, $company->id);
        $this->assertSame('****1234', $state['config']['banks'][0]['accountMasked']);
        $this->assertSame(2, count($state['config']['banks']));

        try {
            $service->save($owner, $company->id, $config, 0);
            $this->fail('Stale payment revision saved.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, DB::table('tenant_payment_settings')->value('revision'));

        $secret = $config;
        $secret['apiKey'] = 'must-not-store';
        try {
            $service->save($owner, $company->id, $secret, 1);
            $this->fail('Raw secret accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $manager = User::create(['name' => 'Manager',
            'email' => 'payment-manager@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $manager->id, 'role' => 'manager']);
        try {
            $service->state($manager, $company->id);
            $this->fail('Manager read tenant payment settings.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'tenant.payment_settings_updated')->count());
    }
}
