<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative installation readiness. Never trusts completedStages
 * alone and never mutates records.
 */
final class InstallationReadinessService
{
    /** @return array{ready:bool,checks:array<string,bool>,failures:array<int,string>} */
    public function evaluate(User $actor, string $companyId, array $data): array
    {
        $owner = DB::table('company_memberships')->where('company_id', $companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->where('role', 'owner')->exists()
            && DB::table('users')->where('id', $actor->getKey())->where('is_active', true)
                ->whereNotNull('email_verified_at')->exists()
            && DB::table('companies')->where('id', $companyId)->where('is_active', true)->exists();
        if (! $owner) {
            throw new AuthorizationException('Only an active verified Owner can evaluate readiness.');
        }

        $company = DB::table('companies')->where('id', $companyId)->first();
        $ownerRow = DB::table('users')->where('id', $actor->getKey())->first();
        $halls = DB::table('halls')->where('company_id', $companyId)->where('is_active', true)->get();
        $checks = [
            'ownerVerified' => $ownerRow !== null && $ownerRow->email_verified_at !== null,
            'ownerMfa' => $ownerRow !== null && ! empty($ownerRow->app_authentication_secret),
            'companyIdentity' => $company !== null
                && trim((string) ($data['company']['legalName'] ?? '')) !== ''
                && preg_match('/\A[A-Z]{3}\z/', (string) ($data['company']['currency'] ?? '')) === 1
                && trim((string) ($data['company']['timezone'] ?? '')) !== '',
            'halls' => $halls->count() >= 1,
            'programs' => $halls->count() >= 1 && $halls->every(
                fn ($hall): bool => (int) $hall->program_rules_revision > 0),
            'pricing' => $halls->count() >= 1 && $halls->every(
                fn ($hall): bool => (int) $hall->seating_price_revision > 0),
            'addons' => $halls->count() >= 1 && $halls->every(
                fn ($hall): bool => (int) $hall->addon_catalog_revision > 0),
            'branding' => DB::table('websites')->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('branding_draft_revision', '>', 0)
                    ->orWhereNotNull('branding_published_revision_id'))->exists(),
            'payments' => DB::table('tenant_payment_settings')
                ->where('company_id', $companyId)->exists(),
            'staff' => $this->staffReady($companyId, $data),
        ];

        try {
            $license = app(CentralLicensingOverviewService::class)
                ->snapshotForCompany($actor, $companyId, now()->toDateTimeImmutable());
            $checks['license'] = in_array($license['state'] ?? null,
                ['licensed', 'expired_grace'], true);
        } catch (\Throwable) {
            $checks['license'] = false;
        }

        $failures = array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok));

        return ['ready' => $failures === [], 'checks' => $checks, 'failures' => $failures];
    }

    private function staffReady(string $companyId, array $data): bool
    {
        if (($data['staff']['ownerOnly'] ?? false) === true) {
            return true;
        }
        $pending = DB::table('staff_invitations')->where('company_id', $companyId)
            ->where('status', 'pending')->where('expires_at', '>', now())->exists();
        $accepted = DB::table('staff_invitations as i')
            ->join('company_memberships as m', function ($join): void {
                $join->on('m.company_id', '=', 'i.company_id')
                    ->on('m.user_id', '=', 'i.accepted_by');
            })
            ->join('users as u', 'u.id', '=', 'i.accepted_by')
            ->where('i.company_id', $companyId)->where('i.status', 'accepted')
            ->where('m.is_active', true)->where('u.is_active', true)
            ->whereNotNull('u.email_verified_at')->exists();

        return $pending || $accepted;
    }
}
