<?php

namespace App\Services;

use App\Mail\OwnerBootstrapMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** One-time, operator-issued first-Owner bootstrap. No default credentials. */
final class OwnerBootstrapService
{
    public function issue(string $companyName, string $ownerName, string $email): array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || trim($companyName) === '' || trim($ownerName) === '') {
            throw new InvalidArgumentException('Company, owner name and valid email are required.');
        }
        if (DB::table('users')->where('email', $email)->exists()
            || DB::table('company_memberships')->where('role', 'owner')->exists()) {
            throw new ConflictHttpException('An Owner already exists for this installation.');
        }
        $token = bin2hex(random_bytes(32));
        DB::table('owner_bootstrap_tokens')->insert([
            'id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $token),
            'company_name' => trim($companyName), 'owner_name' => trim($ownerName),
            'email' => $email, 'expires_at' => now()->addHours(24),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $delivery = 'not_sent';
        try {
            Mail::to($email)->send(new OwnerBootstrapMail($companyName, $token));
            $delivery = 'sent';
        } catch (\Throwable $exception) {
            report($exception);
        }

        return ['token' => $token, 'expiresAt' => now()->addHours(24)->toIso8601String(),
            'delivery' => $delivery];
    }

    public function accept(string $token, string $password): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1 || strlen($password) < 12) {
            throw new InvalidArgumentException('A valid bootstrap token and 12-character password are required.');
        }
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash, $password): array {
            $row = DB::table('owner_bootstrap_tokens')->where('token_hash', $hash)
                ->lockForUpdate()->first();
            if ($row === null || $row->used_at !== null || now()->greaterThan($row->expires_at)) {
                throw new ConflictHttpException('Bootstrap link is invalid, used or expired.');
            }
            if (DB::table('users')->where('email', $row->email)->exists()
                || DB::table('company_memberships')->where('role', 'owner')->exists()) {
                throw new ConflictHttpException('An Owner already exists for this installation.');
            }
            $userId = DB::table('users')->insertGetId([
                'name' => $row->owner_name, 'email' => $row->email,
                'password' => Hash::make($password), 'is_active' => true,
                'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $companyId = (string) Str::uuid();
            DB::table('companies')->insert([
                'id' => $companyId,
                'slug' => Str::slug($row->company_name).'-'.substr($companyId, 0, 8),
                'name' => $row->company_name, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('company_memberships')->insert([
                'company_id' => $companyId, 'user_id' => $userId, 'role' => 'owner',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('owner_bootstrap_tokens')->where('id', $row->id)->update([
                'used_at' => now(), 'user_id' => $userId, 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $userId, 'action' => 'installation.owner_bootstrapped',
                'entity_type' => 'user', 'entity_id' => (string) $userId,
                'before_values' => null,
                'after_values' => json_encode(['role' => 'owner'], JSON_THROW_ON_ERROR),
                'context' => json_encode(['mfa_required' => true], JSON_THROW_ON_ERROR),
            ]);

            return ['userId' => $userId, 'companyId' => $companyId];
        }, 3);
    }
}
