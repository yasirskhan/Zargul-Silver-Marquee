<?php

namespace App\Services;

use App\Mail\StaffInvitationMail;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Owner-controlled, single-use, expiring staff invitations. No default passwords. */
final class StaffInvitationService
{
    private const ROLES = ['admin', 'manager', 'reception', 'operations'];

    public function invite(
        User $actor, string $companyId, string $email, string $role,
        array $hallIds, string $reason,
    ): array {
        $this->authorize($actor, $companyId);
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254
            || ! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('A valid email and approved staff role are required.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new InvalidArgumentException('An invitation reason is required.');
        }
        $hallIds = array_values(array_unique(array_filter($hallIds, 'is_string')));
        foreach ($hallIds as $hallId) {
            if (! Str::isUuid($hallId) || ! DB::table('halls')->where('company_id', $companyId)
                ->where('id', $hallId)->where('is_active', true)->exists()) {
                throw new InvalidArgumentException('Invitation halls must belong to this company.');
            }
        }
        // Existing users may join another authorized company through the same
        // single-use invitation; acceptance attaches a membership instead of
        // creating a duplicate account.
        DB::table('staff_invitations')->where('company_id', $companyId)
            ->where('email', $email)->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
        if (DB::table('staff_invitations')->where('company_id', $companyId)
            ->where('email', $email)->where('status', 'pending')
            ->where('expires_at', '>', now())->exists()) {
            throw new ConflictHttpException('A pending invitation already exists for this email.');
        }
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        DB::transaction(function () use (
            $actor, $companyId, $email, $role, $hallIds, $reason, $token, $id,
        ): void {
            DB::table('staff_invitations')->insert([
                'id' => $id, 'company_id' => $companyId, 'email' => $email,
                'role' => $role, 'hall_ids' => json_encode($hallIds, JSON_THROW_ON_ERROR),
                'token_hash' => hash('sha256', $token), 'status' => 'pending',
                'expires_at' => now()->addDays(7), 'invited_by' => $actor->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $companyId, 'staff.invitation_sent', $id, [
                'email' => $email, 'role' => $role, 'hallIds' => $hallIds,
            ], $reason);
        }, 3);

        $companyName = (string) DB::table('companies')->where('id', $companyId)->value('name');
        $delivery = 'not_sent';
        try {
            Mail::to($email)->send(new StaffInvitationMail($companyName, $token));
            $delivery = 'sent';
        } catch (\Throwable $exception) {
            // The invitation remains valid and auditable; never claim delivery.
            report($exception);
        }

        return ['id' => $id, 'expiresAt' => now()->addDays(7)->toIso8601String(),
            'delivery' => $delivery];
    }

    public function accept(string $token, string $name, string $password): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw new InvalidArgumentException('Invalid invitation token.');
        }
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || strlen($password) < 12) {
            throw new InvalidArgumentException('Provide your name and a password of at least 12 characters.');
        }
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash, $name, $password): array {
            $invite = DB::table('staff_invitations')->where('token_hash', $hash)
                ->lockForUpdate()->first();
            if ($invite === null || $invite->status !== 'pending'
                || now()->greaterThan($invite->expires_at)) {
                throw new ConflictHttpException('Invitation is invalid, used or expired.');
            }
            $company = DB::table('companies')->where('id', $invite->company_id)
                ->where('is_active', true)->first(['id']);
            if ($company === null) {
                throw new ConflictHttpException('Inviting company is no longer active.');
            }
            if (! in_array($invite->role, self::ROLES, true)) {
                throw new ConflictHttpException('Invitation role is no longer permitted.');
            }
            $user = DB::table('users')->where('email', $invite->email)->first();
            if ($user !== null) {
                if (! $user->is_active) {
                    throw new ConflictHttpException('Existing account is inactive.');
                }
                $userId = (int) $user->id;
                if (DB::table('company_memberships')->where('company_id', $invite->company_id)
                    ->where('user_id', $userId)->where('is_active', true)->exists()) {
                    throw new ConflictHttpException('Account already belongs to this company.');
                }
            } else {
                $userId = DB::table('users')->insertGetId([
                    'name' => $name, 'email' => $invite->email,
                    'password' => Hash::make($password), 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $existingMembership = DB::table('company_memberships')->where('company_id', $invite->company_id)
                ->where('user_id', $userId)->first();
            if ($existingMembership) {
                DB::table('company_memberships')->where('id', $existingMembership->id)
                    ->update(['role' => $invite->role, 'is_active' => true, 'updated_at' => now()]);
                $membershipId = (int) $existingMembership->id;
            } else {
                $membershipId = DB::table('company_memberships')->insertGetId([
                    'company_id' => $invite->company_id, 'user_id' => $userId,
                    'role' => $invite->role, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (json_decode($invite->hall_ids, true, 512, JSON_THROW_ON_ERROR) as $hallId) {
                if (! DB::table('halls')->where('company_id', $invite->company_id)
                    ->where('id', $hallId)->where('is_active', true)->exists()) {
                    throw new ConflictHttpException('Invitation hall is no longer active.');
                }
                DB::table('hall_assignments')->insertOrIgnore([
                    'company_id' => $invite->company_id, 'hall_id' => $hallId,
                    'membership_id' => $membershipId,
                ]);
            }
            DB::table('staff_invitations')->where('id', $invite->id)->update([
                'status' => 'accepted', 'accepted_at' => now(),
                'accepted_by' => $userId, 'updated_at' => now(),
            ]);
            $this->audit(null, $invite->company_id, 'staff.invitation_accepted',
                $invite->id, ['userId' => $userId, 'role' => $invite->role], 'Invitation accepted by staff');

            return ['userId' => $userId, 'companyId' => $invite->company_id,
                'role' => $invite->role, 'emailVerified' => false];
        }, 3);
    }

    public function revoke(User $actor, string $companyId, string $id, string $reason): void
    {
        $this->authorize($actor, $companyId);
        if (! Str::isUuid($id) || mb_strlen(trim($reason)) < 3) {
            throw new InvalidArgumentException('A valid invitation and reason are required.');
        }
        DB::transaction(function () use ($actor, $companyId, $id, $reason): void {
            $invite = DB::table('staff_invitations')->where('company_id', $companyId)
                ->where('id', $id)->lockForUpdate()->first();
            if ($invite === null || $invite->status === 'revoked') {
                throw new ConflictHttpException('Invitation is unavailable.');
            }
            if ($invite->accepted_by !== null) {
                DB::table('company_memberships')->where('company_id', $companyId)
                    ->where('user_id', $invite->accepted_by)->update([
                        'is_active' => false, 'updated_at' => now(),
                    ]);
            }
            DB::table('staff_invitations')->where('id', $id)->update([
                'status' => 'revoked', 'revoked_at' => now(),
                'revoke_reason' => trim($reason), 'updated_at' => now(),
            ]);
            $this->audit($actor, $companyId, 'staff.invitation_revoked', $id,
                ['acceptedUserId' => $invite->accepted_by], $reason);
        }, 3);
    }

    public function list(User $actor, string $companyId): array
    {
        $this->authorize($actor, $companyId);

        return DB::table('staff_invitations')->where('company_id', $companyId)
            ->orderByDesc('created_at')->get([
                'id', 'email', 'role', 'hall_ids', 'status', 'expires_at',
                'accepted_at', 'revoked_at', 'created_at',
            ])->map(fn ($row): array => (array) $row)->all();
    }

    private function authorize(User $actor, string $companyId): void
    {
        $allowed = $actor->is_active && $actor->hasVerifiedEmail()
            && DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            && DB::table('company_memberships')->where('company_id', $companyId)
                ->where('user_id', $actor->getKey())->where('is_active', true)
                ->where('role', 'owner')->exists()
            && DB::table('companies')->where('id', $companyId)->where('is_active', true)->exists();
        if (! $allowed) {
            throw new AuthorizationException('Only an active verified owner can manage staff invitations.');
        }
    }

    private function audit(?User $actor, string $companyId, string $action,
        string $id, array $after, string $reason): void
    {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor?->getKey(), 'action' => $action,
            'entity_type' => 'staff_invitation', 'entity_id' => $id,
            'before_values' => null, 'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }
}
