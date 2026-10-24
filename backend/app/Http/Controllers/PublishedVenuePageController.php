<?php

namespace App\Http\Controllers;

use App\Services\SitePublishedHtmlService;
use App\Services\SitePublicCrawlPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use JsonException;

/**
 * Explicit single-host published-snapshot read only. Operator must approve
 * hostname, company, website, HTTPS, real media and public launch separately.
 * No public draft, tenancy selector, enquiry or booking capability.
 */
final class PublishedVenuePageController
{
    public function __invoke(
        Request $request, SitePublishedHtmlService $renderer,
        PublicSiteRequestGate $gate, SitePublicCrawlPolicy $crawl,
        ?string $pageSlug = null,
    ): Response|JsonResponse
    {
        if (config('public_site.enabled') !== true) {
            abort_if($pageSlug !== null, 404);
            return response()->json([
                'application' => 'VenueDesk',
                'state' => 'foundation',
                'notice' => 'The customer website and booking APIs are not yet connected.',
            ], 200, [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $context = $gate->authorize($request);
        $host = $context['host'];
        $companyId = $context['companyId'];
        $websiteId = $context['websiteId'];

        $canIndex = $crawl->canIndex($companyId, $websiteId);
        try {
            $page = $pageSlug === null ? '/' : '/'.$pageSlug;
            $canonical = 'https://'.$host.$page;
            $html = $renderer->render($companyId, $websiteId, $page, $canonical, $canIndex);
        } catch (InvalidArgumentException | JsonException) {
            abort(503, 'The approved public website is unavailable.');
        }
        abort_if($html === null, 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => $canIndex ? 'index, follow' : 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; script-src 'none'; object-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
