<?php

namespace App\Http\Controllers;

use App\Services\SitePublicCrawlPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public-site crawler policy; no indexing before separate operator opt-in. */
final class PublishedVenueRobotsController
{
    public function __invoke(
        Request $request, PublicSiteRequestGate $gate,
        SitePublicCrawlPolicy $crawl,
    ): Response {
        $context = $gate->authorize($request);
        $allow = $crawl->canIndex($context['companyId'], $context['websiteId']);

        return response("User-agent: *\n".($allow ? "Allow: /" : "Disallow: /")."\n"
            .($allow ? "Sitemap: https://".$context['host']."/sitemap.xml\n" : ''), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
