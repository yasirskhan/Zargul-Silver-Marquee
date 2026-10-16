<?php

namespace App\Services;

/**
 * Server-only safe HTML from approved website and branding revisions.
 * No public route, untrusted Blade templates, staging URLs or commerce data.
 */
final class SitePublishedHtmlService
{
    public function __construct(private readonly SitePublishedSnapshotService $snapshots) {}

    public function render(string $companyId, string $websiteId, string $pageSlug): ?string
    {
        $snapshot = $this->snapshots->project($companyId, $websiteId, $pageSlug);

        return $snapshot === null ? null : view('site.published-page', [
            'snapshot' => $snapshot,
        ])->render();
    }
}
