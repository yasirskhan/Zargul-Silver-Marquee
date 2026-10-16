<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('venuedesk:status', function (): void {
    $this->components->info('VenueDesk: Phase 1 tenant foundation. No production bookings or money.');
})->purpose('Display non-production foundation status');

/**
 * Test-only synthetic browser fixture, CLI only. Never exposes an HTTP route,
 * uses live contacts, or allows production fixture writes.
 */
Artisan::command('venuedesk:seed-browser-e2e', function (): int {
    if (! app()->environment('testing') || getenv('BROWSER_E2E_SEED') !== '1') {
        $this->error('Browser fixtures are restricted to isolated testing mode.');

        return 1;
    }

    $owner = \App\Models\User::query()->firstOrCreate(
        ['email' => 'browser-e2e-owner@example.test'],
        [
            'name' => 'Synthetic Browser Owner',
            'password' => 'synthetic-browser-password-only',
            'is_active' => true,
        ],
    );
    $owner->markEmailAsVerified();
    // Reserved non-production RFC-compatible TOTP fixture. App model encrypts it.
    $owner->app_authentication_secret = 'JBSWY3DPEHPK3PXP';
    $owner->save();

    $company = \App\Models\Company::query()->firstOrCreate(
        ['slug' => 'browser-e2e-marquee'],
        ['name' => 'Synthetic Marquee Venue', 'is_active' => true],
    );
    \App\Models\CompanyMembership::query()->firstOrCreate(
        ['company_id' => $company->id, 'user_id' => $owner->id],
        ['role' => 'owner', 'is_active' => true],
    );
    $hall = \App\Models\Hall::query()->firstOrCreate(
        ['company_id' => $company->id, 'slug' => 'synthetic-hall'],
        ['name' => 'Synthetic Test Hall', 'capacity' => 300],
    );

    \Illuminate\Support\Facades\Auth::login($owner);
    $service = app(\App\Services\HallProgramRulesService::class);
    if ((int) $hall->program_rules_revision === 0) {
        $program = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end,
            'setupMinutes' => 20, 'cleaningMinutes' => 20,
            'graceMinutes' => 15, 'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 0, 'rentalRateMinor' => 0,
        ];
        $service->configure(
            $owner, $company->id, $hall->id,
            [
                'morning' => $program('08:00', '11:00'),
                'noon' => $program('12:00', '15:00'),
                'evening' => $program('17:00', '22:00'),
            ],
            'PKR', 0, 'Synthetic browser fixture, no approved commercial prices',
        );
    }
    if (! \Illuminate\Support\Facades\DB::table('booking_drafts')
        ->where('company_id', $company->id)->exists()) {
        $customerId = (string) \Illuminate\Support\Str::uuid();
        app(\App\Services\CustomerProfileService::class)->create(
            $owner, $company->id, $customerId,
            'Synthetic Browser Customer', 'browser-e2e-private@example.test',
            null, 'Private synthetic test fixture only',
        );
        app(\App\Services\BookingDraftService::class)->create(
            $owner, $company->id, (string) \Illuminate\Support\Str::uuid(),
            $hall->id, $customerId, '2026-12-20', 'evening',
            120, 'Synthetic proposal for browser acceptance',
        );
    }
    \Illuminate\Support\Facades\Auth::logout();
    $this->info('Synthetic owner MFA and private unconfirmed proposal fixture created.');

    return 0;
})->purpose('Seed synthetic authenticated browser fixtures in testing only');
