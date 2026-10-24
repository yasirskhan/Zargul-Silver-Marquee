<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    private function readyConfig(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'no-reply@example.test');
        config()->set('mail.mailers.smtp.host', 'smtp.example.test');
    }

    private function owner(): array
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'auth-readiness']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'auth-readiness-owner@example.test',
            'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);

        return [$company, $owner, $membership];
    }

    public function test_readiness_passes_with_smtp_and_a_verified_owner(): void
    {
        $this->readyConfig();
        [$company, $owner] = $this->owner();

        $this->artisan('venuedesk:auth-readiness')
            ->expectsOutputToContain('Mailer: smtp')
            ->expectsOutputToContain('Active verified owners: 1')
            ->expectsOutputToContain('Auth readiness checks passed')
            ->assertExitCode(0);

        $this->assertNotNull($company);
        $this->assertNotNull($owner);
    }

    public function test_log_mailer_and_missing_owner_fail_closed(): void
    {
        $this->readyConfig();
        config()->set('mail.default', 'log');
        $this->artisan('venuedesk:auth-readiness')
            ->expectsOutputToContain('Mailer is "log"')
            ->assertExitCode(1);

        config()->set('mail.default', 'smtp');
        $this->artisan('venuedesk:auth-readiness')
            ->expectsOutputToContain('No active, verified owner account exists')
            ->assertExitCode(1);
    }
}
