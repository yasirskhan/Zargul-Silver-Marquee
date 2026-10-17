<?php

namespace App\Services;

use InvalidArgumentException;
use JsonException;

/**
 * Only the operator opt-in PLUS a currently valid published homepage may
 * advertise a website or published image as crawlable.
 *
 * Robots/sitemap already require exactly the same published-home projection.
 * A secondary page remains readable when Home is offline, but is not indexed.
 */
final class SitePublicCrawlPolicy
{
    public function __construct(private readonly SitePublishedSnapshotService $snapshots) {}

    public function canIndex(string $companyId, string $websiteId): bool
    {
        if (config('public_site.indexing_enabled') !== true) {
            return false;
        }
        try {
            return $this->snapshots->project($companyId, $websiteId, '/') !== null;
        } catch (InvalidArgumentException | JsonException) {
            return false;
        }
    }
}
