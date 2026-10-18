<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only authorization decision for normal VenueDesk operations.
 * Commercial enforcement is disabled unless the vendor release flag is set.
 * Licensing, installation setup and support remain independently accessible.
 */
final class CentralVenueOperationsAccessGate
{
    public function allowsCompany(string $companyId): bool
    {
        if (config('central_control.activation_enabled') !== true) {
            return true;
        }

        try {
            $bound = DB::table('central_installation_activation')
                ->where('singleton_id', 1)
                ->where('company_id', $companyId)
                ->where('status', 'registered_pending_license')
                ->exists();

            if (! $bound) {
                return false;
            }

            $status = app(CentralLicensingOverviewService::class)
                ->snapshot(now()->toDateTimeImmutable());

            return in_array($status['dashboard_policy'] ?? null, ['operational', 'grace'], true);
        } catch (Throwable) {
            // Missing or invalid locally signed licenses cannot grant access.
            return false;
        }
    }
}
