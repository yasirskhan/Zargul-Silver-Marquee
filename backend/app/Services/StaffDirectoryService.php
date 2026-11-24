<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Owner-only staff directory, role and hall-assignment management. */
final class StaffDirectoryService
{
    private const ROLES = ['admin', 'manager', 'operations', 'reception', 'cashier'];

    public function staff(User $actor, string $companyId, string $search = ''): array
    {
        $this->authorizeOwner($actor, $companyId);
        $query = DB::table('company_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.company_id', $companyId)
            ->select(['m.id as membership_id', 'm.user_id', 'm.role', 'm.is_active',
                'u.name', 'u.email', 'u.mfa_required', 'u.email_verified_at'])
            ->orderBy('u.name');
        $needle = trim($search);
        if ($needle !== '') {
            $query->where(function ($q) use ($needle): void {
                $q->where('u.name', 'ilike', '%'.$needle.'%')
                    ->orWhere('u.email', 'ilike', '%'.$needle.'%');
            });
        }
        return $query->get()->map(function ($row) use ($companyId): array {
            $hallIds = DB::table('hall_assignments')->where('company_id', $companyId)
                ->where('membership_id', $row->membership_id)->pluck('hall_id')->all();
            return ['id' => $row->membership_id, 'userId' => $row->user_id,
                'name' => $row->name, 'email' => $row->email, 'role' => $row->role,
                'active' => (bool) $row->is_active, 'mfaRequired' => (bool) $row->mfa_required,
                'verified' => $row->email_verified_at !== null, 'hallIds' => $hallIds,
                'isOwner' => $row->role === 'owner'];
        })->all();
    }

    public function update(User $actor, string $companyId, string $membershipId,
        string $role, array $hallIds, string $reason): void
    {
        $this->authorizeOwner($actor, $companyId);
        if (! ctype_digit($membershipId) || ! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Invalid staff role or membership.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new InvalidArgumentException('A reason between 3 and 300 characters is required.');
        }
        $target = DB::table('company_memberships')->where('company_id', $companyId)
            ->where('id', $membershipId)->first();
        if (! $target) throw new InvalidArgumentException('Staff membership not found.');
        if ($target->role === 'owner') throw new AuthorizationException('Owner membership cannot be changed here.');
        if ((int) $target->user_id === (int) $actor->getKey()) {
            throw new AuthorizationException('You cannot change your own staff role.');
        }
        $halls = array_values(array_unique(array_filter($hallIds, fn ($id) => is_string($id))));
        $valid = DB::table('halls')->where('company_id', $companyId)->where('is_active', true)
            ->whereIn('id', $halls)->pluck('id')->all();
        if (count($valid) !== count($halls)) throw new InvalidArgumentException('Hall assignment is outside this company.');
        DB::transaction(function () use ($actor, $companyId, $membershipId, $target, $role, $valid, $reason): void {
            $before = ['role' => $target->role, 'hallIds' => DB::table('hall_assignments')
                ->where('company_id', $companyId)->where('membership_id', $membershipId)
                ->pluck('hall_id')->all()];
            DB::table('company_memberships')->where('company_id', $companyId)
                ->where('id', $membershipId)->update(['role' => $role, 'updated_at' => now()]);
            DB::table('hall_assignments')->where('company_id', $companyId)
                ->where('membership_id', $membershipId)->delete();
            foreach ($valid as $hallId) {
                DB::table('hall_assignments')->insert([
                    'company_id' => $companyId,
                    'membership_id' => $membershipId, 'hall_id' => $hallId,
                ]);
            }
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'staff.membership_updated',
                'entity_type' => 'company_membership', 'entity_id' => $membershipId,
                'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['role' => $role, 'hallIds' => $valid], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
        });
    }

    public function revoke(User $actor, string $companyId, string $membershipId, string $reason): void
    {
        $this->authorizeOwner($actor, $companyId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new InvalidArgumentException('A reason between 3 and 300 characters is required.');
        }
        $target = DB::table('company_memberships')->where('company_id', $companyId)
            ->where('id', $membershipId)->first();
        if (! $target) throw new InvalidArgumentException('Staff membership not found.');
        if ($target->role === 'owner' || (int) $target->user_id === (int) $actor->getKey()) {
            throw new AuthorizationException('Owner or self membership cannot be revoked.');
        }
        DB::transaction(function () use ($actor, $companyId, $membershipId, $reason): void {
            DB::table('company_memberships')->where('company_id', $companyId)
                ->where('id', $membershipId)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('hall_assignments')->where('company_id', $companyId)
                ->where('membership_id', $membershipId)->delete();
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'staff.membership_revoked',
                'entity_type' => 'company_membership', 'entity_id' => $membershipId,
                'before_values' => json_encode(['isActive' => true], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['isActive' => false], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
        });
    }

    private function authorizeOwner(User $actor, string $companyId): void
    {
        $allowed = DB::table('company_memberships')->where('company_id', $companyId)
            ->where('user_id', $actor->getKey())->where('role', 'owner')
            ->where('is_active', true)->exists();
        if (! $allowed) throw new AuthorizationException('Owner access required.');
    }
}
