<?php

namespace App\Http\Controllers;

use App\Services\SitePublishedWebpService;
use App\Services\SitePublicCrawlPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use JsonException;

/** No staged/raw/original asset URLs: only current-published-page WebP. */
final class PublishedVenueMediaController
{
    public function __invoke(
        Request $request, PublicSiteRequestGate $gate,
        SitePublishedWebpService $reader, SitePublicCrawlPolicy $crawl,
        string $pageSlug, string $mediaId,
    ): Response {
        $context = $gate->authorize($request);
        $slug = $pageSlug === 'home' ? '/' : '/'.$pageSlug;
        try {
            $result = $reader->read($context['companyId'], $context['websiteId'], $slug, $mediaId);
        } catch (InvalidArgumentException | JsonException) {
            abort(503, 'The approved public image is unavailable.');
        }
        abort_if($result === null, 404);

        return response($result['bytes'], 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) $result['length'],
            'ETag' => $result['etag'],
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => $crawl->canIndex($context['companyId'], $context['websiteId'])
                ? 'index, follow' : 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
