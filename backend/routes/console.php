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
    // Isolated browser-only operations user: required MFA, assigned-hall
    // planning permission, and one genuine nonbinding task. No real event.
    $ops = \App\Models\User::query()->firstOrCreate(
        ['email' => 'browser-e2e-operations@example.test'],
        ['name' => 'Synthetic Operations Staff',
            'password' => 'synthetic-browser-operations-only', 'is_active' => true],
    );
    $ops->markEmailAsVerified();
    $ops->app_authentication_secret = 'MFRGGZDFMZTWQ2LK';
    $ops->save();
    $opsMembership = \App\Models\CompanyMembership::query()->firstOrCreate(
        ['company_id' => $company->id, 'user_id' => $ops->id],
        ['role' => 'operations', 'is_active' => true],
    );
    \App\Models\HallAssignment::query()->firstOrCreate([
        'company_id' => $company->id, 'hall_id' => $hall->id,
        'membership_id' => $opsMembership->id,
    ]);
    if (! \Illuminate\Support\Facades\DB::table('operations_plan_tasks')
        ->where('company_id', $company->id)->exists()) {
        $draftId = \Illuminate\Support\Facades\DB::table('booking_drafts')
            ->where('company_id', $company->id)->value('id');
        app(\App\Services\OperationsPlanningService::class)->create(
            $owner, $company->id, (string) \Illuminate\Support\Str::uuid(), $draftId,
            'Synthetic stage lighting checklist', 'Synthetic test-only staff planning',
        );
    }

    // Website acceptance fixture is private, synthetic and created only by
    // this testing-only CLI command, never through a production endpoint.
    $syntheticSiteId = \Illuminate\Support\Facades\DB::table('websites')
        ->where('company_id', $company->id)->value('id');
    if ($syntheticSiteId === null) {
        $syntheticSiteId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('websites')->insert([
            'id' => $syntheticSiteId, 'company_id' => $company->id,
            'name' => 'Synthetic Website Browser Fixture',
        ]);
    }
    if (! \Illuminate\Support\Facades\DB::table('site_pages')
        ->where('company_id', $company->id)->where('website_id', $syntheticSiteId)
        ->where('slug', '/')->exists()) {
        \Illuminate\Support\Facades\DB::table('site_pages')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $company->id, 'website_id' => $syntheticSiteId,
            'slug' => '/', 'title' => 'Synthetic Home Page',
        ]);
    }

    // Synthetic cross-actor approval ensures an owner login shows a real
    // tenant-scoped pending notice, without any actual expense or payment.
    $admin = \App\Models\User::query()->firstOrCreate(
        ['email' => 'browser-e2e-admin@example.test'],
        ['name' => 'Synthetic Approval Administrator', 'password' => 'synthetic-browser-admin-only',
            'is_active' => true],
    );
    $admin->markEmailAsVerified();
    \App\Models\CompanyMembership::query()->firstOrCreate(
        ['company_id' => $company->id, 'user_id' => $admin->id],
        ['role' => 'admin', 'is_active' => true],
    );
    \Illuminate\Support\Facades\Auth::login($admin);
    app(\App\Services\ApprovalWorkflowService::class)->request(
        $admin, $company->id, '00000000-0000-4000-8000-000000000042',
        'expense', 1, 'PKR', null,
        'Synthetic nonapplying expense approval for owner browser review',
    );
    \Illuminate\Support\Facades\Auth::logout();
    $this->info('Synthetic owner MFA, private draft, and nonapplying approval fixture created.');

    return 0;
})->purpose('Seed synthetic authenticated browser fixtures in testing only');

/**
 * Opt-in operator scheduled command. No real sending unless all secrets,
 * private owner linking and HTTPS app URL have been configured.
 */
Artisan::command('venuedesk:deliver-approval-alerts', function (): int {
    $result = app(\App\Services\TelegramApprovalAlertService::class)->deliverPending(10);
    $this->info('Private approval delivery: '.json_encode($result));
    return 0;
})->purpose('Send bounded opt-in owner Telegram alerts; never act on decisions');


/**
 * This command may be invoked hourly by Laravel's scheduler but only sends an
 * HTTPS request when its independent operator gate is ON and the last
 * cryptographically verified central license check is at least 15 days old
 * (or a bounded retry is due). The command is inert on unprovisioned systems.
 */
Artisan::command('venuedesk:check-central-license', function (): int {
    $result=app(\App\Services\CentralScheduledLicenseCheckService::class)
        ->run(now()->toDateTimeImmutable());
    $this->info('HAFZATECH license check: '.$result['state']);
    return $result['state']==='error' ? 1 : 0;
})->purpose('Run gated, due-only signed central license verification');

\Illuminate\Support\Facades\Schedule::command('venuedesk:check-central-license')
    ->hourly()->withoutOverlapping(15);


/** Customer support sync runs independently of the 15-day license cadence.
 * Entirely inert until vendor HTTPS support API and dedicated operator gate
 * are ready. It does not post tenant bookings, payments or private records.
 */
Artisan::command('venuedesk:send-hafzatech-support', function (): int {
    $out=app(\App\Services\CentralSupportOutboundTransport::class)->sendOne();
    $this->info('HAFZATECH support outbox: '.$out['state']);
    return $out['state']==='error' ? 1 : 0;
})->purpose('Deliver one already owner-authorized queued support ticket if enabled');

\Illuminate\Support\Facades\Schedule::command('venuedesk:send-hafzatech-support')
    ->everyFiveMinutes()->withoutOverlapping(10);
