<?php

namespace App\Policies;

use App\Models\CompanyMembership;
use App\Models\User;

final class FinanceAccessPolicy
{
    public function view(User $actor, string $companyId): bool
    {
        return $actor->is_active && $actor->hasVerifiedEmail()
            && CompanyMembership::query()->where('company_id', $companyId)
                ->where('user_id', $actor->getKey())->where('is_active', true)
                ->whereIn('role', ['owner', 'admin'])
                ->whereHas('company', fn ($q) => $q->where('is_active', true))
                ->exists();
    }

    public function post(User $actor, string $companyId): bool
    {
        return $this->view($actor, $companyId)
            && CompanyMembership::query()->where('company_id', $companyId)
                ->where('user_id', $actor->getKey())->where('role', 'owner')
                ->where('is_active', true)->exists();
    }
}
