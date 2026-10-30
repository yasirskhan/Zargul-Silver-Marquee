<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Server-only safe HTML from approved website and branding revisions.
 * No public route, untrusted Blade templates, staging URLs or commerce data.
 */
final class SitePublishedHtmlService
{
    public function __construct(private readonly SitePublishedSnapshotService $snapshots) {}

    public function render(
        string $companyId, string $websiteId, string $pageSlug,
        ?string $canonicalUrl = null, bool $canIndex = false,
    ): ?string
    {
        $snapshot = $this->snapshots->project($companyId, $websiteId, $pageSlug);
        $designerCredit = null;
        if ($snapshot !== null && config('central_control.activation_enabled') === true) {
            $boundCompany = DB::table('central_installation_activation')
                ->where('singleton_id', 1)->value('company_id');
            if ($boundCompany === $companyId) {
                try {
                    $designerCredit = app(CentralLicensingOverviewService::class)
                        ->snapshot(now()->toDateTimeImmutable())['designer_credit'] ?? null;
                } catch (\Throwable) {
                    $designerCredit = null;
                }
            }
        }

        return $snapshot === null ? null : view('site.published-page', [
            'snapshot' => $snapshot,
            'designerCredit' => $designerCredit,
            'canonicalUrl' => $canonicalUrl,
            'canIndex' => $canIndex,
        ])->render();
    }
}
