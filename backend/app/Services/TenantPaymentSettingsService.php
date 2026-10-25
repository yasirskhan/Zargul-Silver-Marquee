<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Venue-owned manual payment instructions. NEVER HAFZATECH billing.
 * Accepts only masked account references and non-secret instructions; no
 * provider API credential, QR upload, transaction reference or settlement is
 * accepted or activated here.
 */
final class TenantPaymentSettingsService
{
    private const METHODS = ['bank_transfer', 'cash', 'qr', 'easypaisa', 'jazzcash', 'raast'];

    public function state(User $actor, string $companyId): array
    {
        $this->authorize($actor, $companyId);
        $row = DB::table('tenant_payment_settings')->where('company_id', $companyId)->first();
        if ($row === null) {
            return ['revision' => 0, 'config' => $this->empty()];
        }

        return ['revision' => (int) $row->revision,
            'config' => json_decode($row->config, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function save(User $actor, string $companyId, array $config, int $expectedRevision): array
    {
        $this->authorize($actor, $companyId);
        $this->rejectSecrets($config);
        $clean = $this->validate($config);
        if ($expectedRevision < 0) {
            throw new InvalidArgumentException('Invalid payment settings revision.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $clean, $expectedRevision,
        ): array {
            $current = DB::table('tenant_payment_settings')->where('company_id', $companyId)
                ->lockForUpdate()->first();
            $live = $current ? (int) $current->revision : 0;
            if ($live !== $expectedRevision) {
                throw new ConflictHttpException('Payment settings changed; reload before saving.');
            }
            $next = $live + 1;
            if ($current) {
                DB::table('tenant_payment_settings')->where('company_id', $companyId)->update([
                    'config' => json_encode($clean, JSON_THROW_ON_ERROR),
                    'revision' => $next, 'updated_by' => $actor->getKey(), 'updated_at' => now(),
                ]);
            } else {
                try {
                    DB::table('tenant_payment_settings')->insert([
                        'id' => (string) Str::uuid(), 'company_id' => $companyId,
                        'config' => json_encode($clean, JSON_THROW_ON_ERROR),
                        'revision' => $next, 'updated_by' => $actor->getKey(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (\Illuminate\Database\QueryException $exception) {
                    throw new ConflictHttpException(
                        'Payment settings were created concurrently; reload before saving.',
                        0, $exception,
                    );
                }
            }
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'tenant.payment_settings_updated',
                'entity_type' => 'tenant_payment_settings', 'entity_id' => $companyId,
                'before_values' => null,
                'after_values' => json_encode([
                    'revision' => $next, 'methods' => $clean['methods'],
                    'bankCount' => count($clean['banks']),
                    'depositPercent' => $clean['depositPercent'],
                    'providerApiEnabled' => false,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['no_live_payment_activation' => true], JSON_THROW_ON_ERROR),
            ]);

            return ['revision' => $next, 'config' => $clean];
        }, 3);
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
            throw new AuthorizationException('Only an active verified owner can configure venue payment settings.');
        }
    }

    private function empty(): array
    {
        return ['banks' => [], 'instructions' => '', 'qrInstructions' => '',
            'methods' => [], 'depositPercent' => 0, 'providerApiEnabled' => false];
    }

    private function rejectSecrets(array $config): void
    {
        $forbidden = ['apikey', 'api_key', 'secret', 'password', 'token', 'privatekey',
            'private_key', 'accesskey', 'access_key'];
        $walk = function (array $value) use (&$walk, $forbidden): void {
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                    throw new InvalidArgumentException('Raw payment credentials are not accepted by the wizard.');
                }
                if (is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($config);
    }

    private function validate(array $config): array
    {
        $banks = is_array($config['banks'] ?? null) ? $config['banks'] : [];
        if (count($banks) > 5) {
            throw new InvalidArgumentException('At most five tenant bank accounts are allowed.');
        }
        $cleanBanks = [];
        foreach ($banks as $bank) {
            if (! is_array($bank)) {
                throw new InvalidArgumentException('Invalid bank entry.');
            }
            $name = trim((string) ($bank['bankName'] ?? ''));
            $branch = trim((string) ($bank['branch'] ?? ''));
            $masked = trim((string) ($bank['accountMasked'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120
                || mb_strlen($branch) > 160
                || preg_match('/\A(?:\*{2,}|X{2,}|x{2,})[0-9]{2,6}\z/', $masked) !== 1
                || preg_match('/[<>[:cntrl:]]/u', $name.$branch.$masked)) {
                throw new InvalidArgumentException('Bank details require a name and a masked account reference only.');
            }
            $cleanBanks[] = [
                'bankName' => $name, 'branch' => $branch, 'accountMasked' => $masked,
            ];
        }
        $methods = is_array($config['methods'] ?? null) ? array_values(array_unique($config['methods'])) : [];
        foreach ($methods as $method) {
            if (! is_string($method) || ! in_array($method, self::METHODS, true)) {
                throw new InvalidArgumentException('Invalid tenant payment method.');
            }
        }
        $deposit = filter_var($config['depositPercent'] ?? 0, FILTER_VALIDATE_INT);
        if ($deposit === false || $deposit < 0 || $deposit > 100) {
            throw new InvalidArgumentException('Deposit percentage must be between 0 and 100.');
        }
        if (($config['providerApiEnabled'] ?? false) === true) {
            throw new InvalidArgumentException('Provider API activation is not available in setup.');
        }
        $instructions = trim((string) ($config['instructions'] ?? ''));
        $qr = trim((string) ($config['qrInstructions'] ?? ''));
        if (mb_strlen($instructions) > 1000 || mb_strlen($qr) > 1000
            || preg_match('/[<>[:cntrl:]]/u', $instructions.$qr)) {
            throw new InvalidArgumentException('Payment instructions are invalid.');
        }

        return ['banks' => $cleanBanks, 'instructions' => $instructions,
            'qrInstructions' => $qr, 'methods' => $methods,
            'depositPercent' => $deposit, 'providerApiEnabled' => false];
    }
}
