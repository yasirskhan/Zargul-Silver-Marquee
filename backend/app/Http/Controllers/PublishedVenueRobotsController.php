<?php

namespace App\Http\Controllers;

use App\Services\SitePublishedSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use JsonException;

/** Public-site crawler policy; no indexing before separate operator opt-in. */
final class PublishedVenueRobotsController
{
    public function __invoke(
        Request $request, PublicSiteRequestGate $gate,
        SitePublishedSnapshotService $snapshots,
    ): Response {
        $context = $gate->authorize($request);
        $allow = false;
        if (config('public_site.indexing_enabled') === true) {
            try {
                $allow = $snapshots->project(
                    $context['companyId'], $context['websiteId'], '/'
                ) !== null;
            } catch (InvalidArgumentException | JsonException) {
                $allow = false;
            }
        }

        return response("User-agent: *\n".($allow ? "Allow: /" : "Disallow: /")."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
