<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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

        return ['host' => $host, 'companyId' => $companyId, 'websiteId' => $websiteId];
    }
}
