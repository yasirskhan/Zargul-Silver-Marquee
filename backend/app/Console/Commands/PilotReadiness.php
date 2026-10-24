<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, fail-closed staging/pilot readiness report. Never changes data. */
final class PilotReadiness extends Command
{
    protected $signature = 'venuedesk:pilot-readiness {--json : Emit a JSON report}';

    protected $description = 'Check staging gates, database, storage, MFA owners and pilot roles without changing data';

    public function handle(): int
    {
        $failures = [];
        $warnings = [];
        $checks = [];

        $environment = (string) app()->environment();
        $checks['environment'] = $environment;
        if ($environment === 'production') {
            $failures[] = 'Pilot readiness must not run against production.';
        }

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
            $value = config($flag);
            $checks[$flag] = $value;
            if ($value === true) {
                $failures[] = 'Release flag must remain OFF for pilot preparation: '.$flag;
            }
        }

        $driver = DB::connection()->getDriverName();
        $checks['database_driver'] = $driver;
        if ($driver !== 'pgsql') {
            $failures[] = 'Pilot readiness requires PostgreSQL; current driver is '.$driver.'.';
        }
        foreach (['users', 'companies', 'company_memberships', 'halls',
            'venue_enquiries', 'audit_events'] as $table) {
            if (! Schema::hasTable($table)) {
                $failures[] = 'Required table is missing: '.$table;
            }
        }

        $privatePath = storage_path('app/private');
        $storageRoot = storage_path();
        $checks['storage_writable'] = is_dir($storageRoot) && is_writable($storageRoot);
        $checks['private_storage_present'] = is_dir($privatePath);
        if (! $checks['storage_writable']) {
            $failures[] = 'Storage root is not writable: '.$storageRoot;
        }
        if (! $checks['private_storage_present']) {
            $warnings[] = 'Private document directory does not exist yet: '.$privatePath;
        }

        $activeOwners = DB::table('users')
            ->join('company_memberships', 'company_memberships.user_id', '=', 'users.id')
            ->join('companies', 'companies.id', '=', 'company_memberships.company_id')
            ->where('company_memberships.role', 'owner')
            ->where('company_memberships.is_active', true)
            ->where('companies.is_active', true)
            ->where('users.is_active', true)
            ->whereNotNull('users.email_verified_at')
            ->distinct()->count('users.id');
        $checks['active_verified_owners'] = $activeOwners;
        if ($activeOwners < 1) {
            $failures[] = 'At least one active, verified owner is required.';
        }

        $roles = DB::table('company_memberships')->where('is_active', true)
            ->whereIn('role', ['owner', 'admin', 'manager', 'operations', 'reception'])
            ->distinct()->pluck('role')->all();
        $checks['pilot_roles'] = $roles;
        foreach (['owner', 'admin', 'manager', 'operations', 'reception'] as $role) {
            if (! in_array($role, $roles, true)) {
                $warnings[] = 'No active pilot account for role: '.$role;
            }
        }

        $mfaEnrolled = DB::table('users')->whereNotNull('app_authentication_secret')->count();
        $checks['mfa_enrolled_users'] = $mfaEnrolled;
        if ($mfaEnrolled < $activeOwners) {
            $warnings[] = 'Some active owners have not enrolled MFA; Filament forces enrolment at next login.';
        }
        if (in_array((string) config('mail.default'), ['log', 'array', 'null'], true)) {
            $warnings[] = 'Mailer is not configured for external delivery.';
        }

        $checks['failures'] = $failures;
        $checks['warnings'] = $warnings;
        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->line('VenueDesk pilot readiness');
            $this->line('Environment: '.$environment);
            $this->line('Database driver: '.$driver);
            $this->line('Active verified owners: '.$activeOwners);
            $this->line('MFA-enrolled users: '.$mfaEnrolled);
            $this->line('Pilot roles: '.(implode(', ', $roles) ?: 'none'));
            foreach ($warnings as $warning) {
                $this->warn($warning);
            }
            foreach ($failures as $failure) {
                $this->error($failure);
            }
        }

        if ($failures !== []) {
            if (! $this->option('json')) {
                $this->error('Pilot readiness failed closed.');
            }

            return self::FAILURE;
        }
        if (! $this->option('json')) {
            $this->info('Executable pilot readiness passed. Real staging, backup restore and operator signoff remain external.');
        }

        return self::SUCCESS;
    }
}
