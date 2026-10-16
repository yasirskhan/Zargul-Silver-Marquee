<?php

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;

class TenantAccess
{
    private function membership(User $user, string $companyId): ?CompanyMembership
    {
        if (! $user->is_active) {
            return null;
        }

        return CompanyMembership::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->first();
    }

    public function canViewHall(User $user, Hall $hall): bool
    {
        if (! $hall->is_active || ! ($membership = $this->membership($user, $hall->company_id))) {
            return false;
        }

        if (in_array($membership->role, ['owner', 'admin'], true)) {
            return true;
        }

        return $membership->hallAssignments()->where('hall_id', $hall->getKey())->exists();
    }

    public function canEditWebsite(User $user, string $companyId): bool
    {
        $membership = $this->membership($user, $companyId);

        return $membership && (
            in_array($membership->role, ['owner', 'admin'], true)
            || $membership->can_edit_site
        );
    }

    public function canPublishWebsite(User $user, string $companyId): bool
    {
        $membership = $this->membership($user, $companyId);

        return $membership && (
            $membership->role === 'owner'
            || $membership->can_publish_site
        );
    }

    /** Sensitive hall pricing and hours are configured by active owner/admin only. */
    public function canConfigureHall(User $user, Hall $hall): bool
    {
        $membership = $this->membership($user, $hall->company_id);

        return $hall->is_active
            && $membership !== null
            && in_array($membership->role, ['owner', 'admin'], true);
    }

    public function canManageBookings(User $user, Hall $hall): bool
    {
        $membership = $this->membership($user, $hall->company_id);

        return $membership
            && in_array($membership->role, ['owner', 'admin', 'manager', 'reception'], true)
            && $this->canViewHall($user, $hall);
    }
}
