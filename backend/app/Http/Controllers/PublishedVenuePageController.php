<?php

namespace App\Http\Controllers;

use App\Services\SitePublishedHtmlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Explicit single-host published-snapshot read only. Operator must approve
 * hostname, company, website, HTTPS, real media and public launch separately.
 * No public draft, tenancy selector, enquiry or booking capability.
 */
final class PublishedVenuePageController
{
    public function __invoke(Request $request, SitePublishedHtmlService $renderer, ?string $pageSlug = null): Response|JsonResponse
    {
        if (config('public_site.enabled') !== true) {
            abort_if($pageSlug !== null, 404);
            return response()->json([
                'application' => 'VenueDesk',
                'state' => 'foundation',
                'notice' => 'The customer website and booking APIs are not yet connected.',
            ]);
        }

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
        // Never select a company/site from a user-controlled query string,
        // path, wildcard hostname or forwarded header.
        abort_unless(hash_equals($host, $request->getHost()), 404);
        if (app()->environment('production')) {
            abort_unless($request->isSecure(), 403);
        }

        try {
            $page = $pageSlug === null ? '/' : '/'.$pageSlug;
            $canonical = 'https://'.$host.$page;
            $html = $renderer->render($companyId, $websiteId, $page, $canonical);
        } catch (InvalidArgumentException | JsonException) {
            abort(503, 'The approved public website is unavailable.');
        }
        abort_if($html === null, 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; script-src 'none'; object-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
