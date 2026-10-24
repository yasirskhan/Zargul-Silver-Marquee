<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    private function accounts(): void
    {
        $company = Company::create(['name' => 'Pilot Venue', 'slug' => 'pilot-venue']);
        foreach (['owner', 'admin', 'manager', 'operations', 'reception'] as $role) {
            $user = User::create([
                'name' => ucfirst($role), 'email' => 'pilot-'.$role.'@example.test',
                'password' => 'synthetic-only',
            ]);
            $user->markEmailAsVerified();
            CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $user->id, 'role' => $role,
            ]);
        }
    }

    private function gatesOff(): void
    {
        foreach ([
            'booking_controls.confirmation_enabled',
            'finance_controls.posting_enabled',
            'invoice_controls.issuance_enabled',
            'payment_controls.enabled',
            'quote_controls.issuance_enabled',
            'refund_controls.enabled',
            'event_controls.recording_enabled',
            'public_site.enabled',
            'central_control.activation_enabled',
        ] as $flag) {
            config()->set($flag, false);
        }
    }

    public function test_pilot_readiness_passes_off_gate_staging_and_fails_when_a_release_flag_is_on(): void
    {
        $this->gatesOff();
        $this->accounts();

        $this->artisan('venuedesk:pilot-readiness')
            ->expectsOutputToContain('Database driver: pgsql')
            ->expectsOutputToContain('Active verified owners: 1')
            ->expectsOutputToContain('Executable pilot readiness passed')
            ->assertExitCode(0);

        config()->set('booking_controls.confirmation_enabled', true);
        $this->artisan('venuedesk:pilot-readiness')
            ->expectsOutputToContain('booking_controls.confirmation_enabled')
            ->assertExitCode(1);
    }
}
