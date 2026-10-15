<?php

namespace App\Policies;

use App\Models\SitePage;
use App\Models\User;
use App\Services\TenantAccess;
use Illuminate\Auth\Access\Response;

final class SitePagePolicy
{
    public function __construct(private readonly TenantAccess $tenantAccess) {}

    public function view(User $user, SitePage $page): Response
    {
        return $this->canEdit($user, $page)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, SitePage $page): Response
    {
        return $this->view($user, $page);
    }

    public function publish(User $user, SitePage $page): Response
    {
        return $user->hasVerifiedEmail()
            && $this->validPage($page)
            && $this->tenantAccess->canPublishWebsite($user, $page->company_id)
                ? Response::allow()
                : Response::denyAsNotFound();
    }

    public function delete(User $user, SitePage $page): Response
    {
        // History, published references and audit must be preserved.
        return Response::deny('Website pages must be retired through an audited workflow.');
    }

    private function canEdit(User $user, SitePage $page): bool
    {
        return $user->hasVerifiedEmail()
            && $this->validPage($page)
            && $this->tenantAccess->canEditWebsite($user, $page->company_id);
    }

    private function validPage(SitePage $page): bool
    {
        // Do not trust arbitrary in-memory Eloquent models as authorization facts.
        return $page->exists
            && SitePage::query()
                ->whereKey($page->getKey())
                ->where('company_id', $page->company_id)
                ->whereHas('website', fn ($query) => $query
                    ->where('company_id', $page->company_id)
                    ->where('is_active', true))
                ->exists();
    }
}
