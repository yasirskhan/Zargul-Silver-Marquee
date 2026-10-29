<?php

namespace App\Http\Controllers;

use App\Services\CentralLicensingOverviewService;
use Illuminate\Http\Request;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operator-configured, fail-closed domain and tenant publication gate. */
final class PublicSiteRequestGate
{
    public function authorize(Request $request): array
    {
        abort_unless(config('public_site.enabled') === true, 404);

        $host = config('public_site.host');
        $companyId = config('public_site.company_id');
        $websiteId = config('public_site.website_id');
        // Isolated authenticated-browser fixtures may omit fixed IDs. This
        // fallback is impossible in production and still requires one active,
        // published website row.
        if (config('public_site.test_fallback') === true
            && app()->environment('testing')
            && (! is_string($companyId) || ! Str::isUuid($companyId)
                || ! is_string($websiteId) || ! Str::isUuid($websiteId))) {
            $licensedCompany = DB::table('central_installation_activation')
                ->where('singleton_id', 1)->value('company_id');
            $site = DB::table('websites')->join('companies', 'websites.company_id', '=', 'companies.id')
                ->where('websites.is_active', true)->where('companies.is_active', true)
                ->when($licensedCompany, fn ($query) => $query->where('websites.company_id', $licensedCompany))
                ->orderBy('websites.id')->first(['websites.id', 'websites.company_id']);
            $companyId = $site?->company_id;
            $websiteId = $site?->id;
        }
        if (! is_string($host) || strlen($host) > 253
            || ! preg_match('/\A[a-z0-9][a-z0-9.-]*[a-z0-9]\z/D', $host)
            || str_contains($host, '..') || ! str_contains($host, '.')
            || ! is_string($companyId) || ! Str::isUuid($companyId)
            || ! is_string($websiteId) || ! Str::isUuid($websiteId)) {
            abort(503, 'The approved public website is unavailable.');
        }
        abort_unless(hash_equals($host, $request->getHost()), 404);
        if (app()->environment('production')) {
            abort_unless($request->isSecure(), 403);
        }

        // Operator host settings cannot substitute for DNS ownership.
        // In production, serve only a VERIFIED claim for the exact hostname,
        // company and website. This never changes any launch/indexing flag.
        if (app()->environment('production')
            || config('public_site.require_verified_domain') === true) {
            $verified = DB::table('site_domain_claims')
                ->where('company_id', $companyId)
                ->where('website_id', $websiteId)
                ->where('domain', $host)
                ->where('status', 'verified')
                ->whereNotNull('verified_at')->exists();
            abort_unless($verified, 503, 'Verified DNS domain ownership is required.');
        }

        // Commercial enforcement is entirely inert in default builds.
        // Once explicitly released, the exact approved public venue must also
        // be the company and domain of the registered central installation.
        // Missing/unsigned/expired grants close ALL website, media and crawl
        // endpoints without altering existing bookings or financial records.
        if (config('central_control.activation_enabled') === true) {
            $enrolled = DB::table('central_installation_activation')
                ->where('singleton_id', 1)
                ->where('company_id', $companyId)
                ->where('approved_domain', $host)
                ->where('status', 'registered_pending_license')
                ->exists();
            abort_unless($enrolled, 503, 'The venue website is temporarily unavailable.');
            try {
                $license = app(CentralLicensingOverviewService::class)
                    ->snapshot(now()->toDateTimeImmutable());
            } catch (Throwable) {
                abort(503, 'The venue website is temporarily unavailable.');
            }
            abort_unless(($license['public_policy'] ?? null) === 'open', 503,
                'The venue website is temporarily unavailable.');
        }

        return ['host' => $host, 'companyId' => $companyId, 'websiteId' => $websiteId];
    }
}
