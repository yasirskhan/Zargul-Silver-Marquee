<?php

namespace App\Console\Commands;

use App\Models\User;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\DB;

/**
 * Non-destructive readiness report for authentication, MFA, verification and
 * operational email. It never sends mail or changes accounts/flags.
 */
final class AuthReadiness extends Command
{
    protected $signature = 'venuedesk:auth-readiness';

    protected $description = 'Check authentication, MFA, verification and email readiness without sending mail';

    public function handle(): int
    {
        $failures = [];
        $warnings = [];

        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array', 'null'], true)) {
            $failures[] = 'Mailer is "'.$mailer.'"; configure a real SMTP/provider transport before operational email.';
        }
        if (blank(config('mail.from.address'))) {
            $failures[] = 'MAIL_FROM_ADDRESS is not configured.';
        }
        if (blank(config('app.key'))) {
            $failures[] = 'APP_KEY is not configured; encrypted exports and sessions are unsafe.';
        }
        if (! is_a(User::class, MustVerifyEmail::class, true)) {
            $failures[] = 'User does not require email verification.';
        }
        if (! is_a(User::class, HasAppAuthentication::class, true)
            || ! is_a(User::class, HasAppAuthenticationRecovery::class, true)) {
            $failures[] = 'User does not implement app-based MFA and recovery interfaces.';
        }
        if ($mailer === 'smtp' && blank(config('mail.mailers.smtp.host'))) {
            $warnings[] = 'SMTP host is blank; verify provider credentials and delivery externally.';
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
        if ($activeOwners < 1) {
            $failures[] = 'No active, verified owner account exists.';
        }

        $mfaConfigured = DB::table('users')->whereNotNull('app_authentication_secret')->count();
        if ($mfaConfigured < $activeOwners) {
            $warnings[] = 'Some active owners have not enrolled MFA; Filament forces enrolment at next login.';
        }

        $this->line('VenueDesk authentication readiness');
        $this->line('Mailer: '.$mailer);
        $this->line('From address: '.(config('mail.from.address') ?: 'missing'));
        $this->line('Active verified owners: '.$activeOwners);
        $this->line('MFA-enrolled users: '.$mfaConfigured);
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
        foreach ($failures as $failure) {
            $this->error($failure);
        }

        if ($failures !== []) {
            $this->error('Auth readiness failed.');

            return self::FAILURE;
        }

        $this->info('Auth readiness checks passed. Real provider delivery and operator acceptance remain external.');

        return self::SUCCESS;
    }
}
