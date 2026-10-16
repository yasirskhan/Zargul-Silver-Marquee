<?php

namespace App\Http\Controllers;

use App\Services\SitePublishedSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use JsonException;

/** Crawl only CURRENT published, valid site pages after explicit launch opt-in. */
final class PublishedVenueSitemapController
{
    private const SLUGS = ['/', '/halls', '/packages', '/services',
        '/gallery', '/about', '/contact', '/privacy', '/terms'];

    public function __invoke(
        Request $request, PublicSiteRequestGate $gate,
        SitePublishedSnapshotService $snapshots,
    ): Response {
        $context = $gate->authorize($request);
        abort_unless(config('public_site.indexing_enabled') === true, 404);
        try {
            $home = $snapshots->project($context['companyId'], $context['websiteId'], '/');
        } catch (InvalidArgumentException | JsonException) {
            abort(503, 'Approved homepage is unavailable.');
        }
        abort_if($home === null, 404);

        $urls = ['https://'.$context['host'].'/'];
        foreach (self::SLUGS as $slug) {
            if ($slug === '/') {
                continue;
            }
            try {
                $valid = $snapshots->project(
                    $context['companyId'], $context['websiteId'], $slug
                ) !== null;
            } catch (InvalidArgumentException | JsonException) {
                $valid = false;
            }
            if ($valid) {
                $urls[] = 'https://'.$context['host'].$slug;
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }
        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
