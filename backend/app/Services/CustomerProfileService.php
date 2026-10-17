<?php

namespace App\Services;

use App\Models\User;
use App\Models\Hall;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Internal staff-only encrypted contact store. No public intake/CRM route.
 * The change record is redacted: never log cleartext contact information.
 */
final class CustomerProfileService
{
    public function __construct(private readonly TenantAccess $access) {}

    public function create(
        User $actor, string $companyId, string $customerId,
        string $name, ?string $email, ?string $phone, string $reason,
        ?string $assignedBookingHallId = null,
    ): array {
        $this->authorizeCreate($actor, $companyId, $assignedBookingHallId);
        if (! Str::isUuid($customerId)) {
            throw new InvalidArgumentException('Customer request ID must be a UUID.');
        }
        [$name, $email, $phone] = $this->validatedContact($name, $email, $phone);
        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $actor, $companyId, $customerId, $name, $email, $phone, $reason,
            $assignedBookingHallId
        ): array {
            $existing = DB::table('customer_profiles')->where('id', $customerId)
                ->lockForUpdate()->first();
            // A profile request can wait on an existing row lock. Never
            // accept a retry or new ciphertext based on a stale membership.
            $this->authorizeCreate($actor, $companyId, $assignedBookingHallId);
            if ($existing) {
                if ($existing->company_id === $companyId
                    && (int) $existing->created_by === (int) $actor->getKey()
                    && (int) $existing->revision === 1 && $existing->is_active
                    && Crypt::decryptString($existing->name_ciphertext) === $name
                    && ($existing->email_ciphertext ? Crypt::decryptString($existing->email_ciphertext) : null) === $email
                    && ($existing->phone_ciphertext ? Crypt::decryptString($existing->phone_ciphertext) : null) === $phone) {
                    return ['customerId' => $customerId, 'revision' => 1];
                }
                throw new ConflictHttpException('Customer creation key already belongs to another record.');
            }

            DB::table('customer_profiles')->insert([
                'id' => $customerId, 'company_id' => $companyId,
                'name_ciphertext' => Crypt::encryptString($name),
                'email_ciphertext' => $email === null ? null : Crypt::encryptString($email),
                'phone_ciphertext' => $phone === null ? null : Crypt::encryptString($phone),
                'revision' => 1, 'is_active' => true, 'created_by' => $actor->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $summary = $this->summary(1, true, $email, $phone);
            $this->record($actor, $companyId, $customerId, 'created', 1,
                null, $summary, $reason);

            return ['customerId' => $customerId, 'revision' => 1];
        }, 3);
    }

    public function current(User $actor, string $companyId, string $customerId): ?array
    {
        $this->authorize($actor, $companyId);
        if (! Str::isUuid($customerId)) {
            throw new InvalidArgumentException('Customer ID must be a UUID.');
        }
        $saved = DB::table('customer_profiles')->where('company_id', $companyId)
            ->where('id', $customerId)->where('is_active', true)->first();
        if (! $saved) {
            return null;
        }

        return [
            'customerId' => $saved->id, 'revision' => (int) $saved->revision,
            'name' => Crypt::decryptString($saved->name_ciphertext),
            'email' => $saved->email_ciphertext ? Crypt::decryptString($saved->email_ciphertext) : null,
            'phone' => $saved->phone_ciphertext ? Crypt::decryptString($saved->phone_ciphertext) : null,
        ];
    }

    public function update(
        User $actor, string $companyId, string $customerId,
        string $name, ?string $email, ?string $phone,
        int $expectedRevision, string $reason,
    ): int {
        $this->authorize($actor, $companyId);
        if (! Str::isUuid($customerId) || $expectedRevision < 1) {
            throw new InvalidArgumentException('Customer ID or expected revision is invalid.');
        }
        [$name, $email, $phone] = $this->validatedContact($name, $email, $phone);
        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $actor, $companyId, $customerId, $name, $email, $phone, $expectedRevision, $reason
        ): int {
            $saved = DB::table('customer_profiles')->where('company_id', $companyId)
                ->where('id', $customerId)->where('is_active', true)->lockForUpdate()->first();
            // Recheck privilege AFTER the row is locked. Otherwise an owner
            // whose membership was revoked while waiting could update PII.
            $this->authorize($actor, $companyId);
            if (! $saved) {
                throw new AuthorizationException('Customer record not available.');
            }
            if ((int) $saved->revision !== $expectedRevision) {
                throw new ConflictHttpException('Customer contact changed; reload before saving.');
            }

            $before = $this->summary((int) $saved->revision, true,
                $saved->email_ciphertext, $saved->phone_ciphertext);
            $next = $expectedRevision + 1;
            DB::table('customer_profiles')->where('company_id', $companyId)
                ->where('id', $customerId)->update([
                    'name_ciphertext' => Crypt::encryptString($name),
                    'email_ciphertext' => $email === null ? null : Crypt::encryptString($email),
                    'phone_ciphertext' => $phone === null ? null : Crypt::encryptString($phone),
                    'revision' => $next, 'updated_at' => now(),
                ]);
            $this->record($actor, $companyId, $customerId, 'updated', $next,
                $before, $this->summary($next, true, $email, $phone), $reason);

            return $next;
        }, 3);
    }

    private function authorizeCreate(User $actor, string $companyId, ?string $hallId): void
    {
        if ($hallId === null) {
            $this->authorize($actor, $companyId);
            return;
        }
        $hall = Hall::query()->where('company_id', $companyId)
            ->where('id', $hallId)->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            || ! DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('Current assigned-hall staff access is required to create booking contacts.');
        }
    }

    private function authorize(User $actor, string $companyId): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            // Auth may still hold an old hydrated User object after an account
            // was disabled or its email verification was revoked in SQL.
            || ! DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            || ! $this->access->canManageCustomerProfiles($actor, $companyId)) {
            throw new AuthorizationException('You cannot manage customer contact records.');
        }
    }

    private function validatedContact(string $name, ?string $email, ?string $phone): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $email = $email === null || trim($email) === '' ? null : mb_strtolower(trim($email));
        $phone = $phone === null || trim($phone) === '' ? null : trim($phone);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120
            || preg_match('/[<>]/u', $name)
            || ($email !== null && (mb_strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)))
            || ($phone !== null && ! preg_match('/\A[+0-9 ()-]{7,32}\z/', $phone))) {
            throw new InvalidArgumentException('Customer contact fields do not meet validation rules.');
        }

        return [$name, $email, $phone];
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Customer contact change reason is required.');
        }

        return $reason;
    }

    private function summary(int $revision, bool $active, ?string $email, ?string $phone): array
    {
        return [
            'revision' => $revision, 'active' => $active,
            'hasEmail' => $email !== null, 'hasPhone' => $phone !== null,
        ];
    }

    private function record(
        User $actor, string $companyId, string $customerId, string $action,
        int $revision, ?array $before, array $after, string $reason,
    ): void {
        DB::table('customer_profile_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'customer_id' => $customerId, 'action' => $action, 'revision' => $revision,
            'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_id' => $actor->getKey(), 'reason' => $reason,
        ]);
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(), 'action' => 'customer_profile.'.$action,
            'entity_type' => 'customer_profile', 'entity_id' => $customerId,
            'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }
}
