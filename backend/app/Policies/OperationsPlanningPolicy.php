<?php

namespace App\Policies;

use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class OperationsPlanningPolicy
{
    public function membership(User $actor, string $companyId): ?CompanyMembership
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->is_active || ! $actor->hasVerifiedEmail()) {
            return null;
        }
        return CompanyMembership::query()->where('company_id', $companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin', 'manager', 'operations'])
            ->whereHas('company', fn ($q) => $q->where('is_active', true))->first();
    }

    public function plan(User $actor, string $companyId, string $hallId): bool
    {
        $membership = $this->membership($actor, $companyId);
        if (! $membership || ! DB::table('halls')->where('id', $hallId)
            ->where('company_id', $companyId)->where('is_active', true)->exists()) {
            return false;
        }
        if (in_array($membership->role, ['owner', 'admin'], true)) {
            return true;
        }
        return DB::table('hall_assignments')->where('company_id', $companyId)
            ->where('membership_id', $membership->getKey())
            ->where('hall_id', $hallId)->exists();
    }
}
