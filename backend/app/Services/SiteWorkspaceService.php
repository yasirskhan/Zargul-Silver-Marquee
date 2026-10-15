<?php

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\SitePage;
use App\Models\User;
use App\Models\Website;

final class SiteWorkspaceService
{
    public function __construct(private readonly TenantAccess $access) {}

    /** Companies listed here are the only companies a verified site editor may inspect. */
    public function companyOptions(User $actor): array
    {
        if (! $actor->is_active || ! $actor->hasVerifiedEmail()) {
            return [];
        }

        $memberships = CompanyMembership::query()
            ->where('user_id', $actor->getKey())
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->with('company')
            ->get();

        $options = [];
        foreach ($memberships as $membership) {
            $companyId = $membership->company_id;
            if ($this->access->canEditWebsite($actor, $companyId)
                && Website::query()->forCompany($companyId)->where('is_active', true)->exists()) {
                $options[$companyId] = $membership->company->name;
            }
        }

        asort($options);

        return $options;
    }

    public function pageOptions(User $actor, string $companyId): array
    {
        if (! array_key_exists($companyId, $this->companyOptions($actor))) {
            return [];
        }

        return SitePage::query()
            ->forCompany($companyId)
            ->whereHas('website', fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true))
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /** Never trust a Livewire-supplied tenant or page ID without this fresh scope. */
    public function page(User $actor, string $companyId, string $pageId): ?SitePage
    {
        if (! array_key_exists($companyId, $this->companyOptions($actor))) {
            return null;
        }

        return SitePage::query()
            ->forCompany($companyId)
            ->whereKey($pageId)
            ->whereHas('website', fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true))
            ->first();
    }
}
