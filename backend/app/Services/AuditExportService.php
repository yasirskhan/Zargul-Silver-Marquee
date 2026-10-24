<?php

namespace App\Services;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Internal encrypted audit export foundation. Owner/admin ciphertext download
 * is supported; approved off-site storage, key custody, retention and recovery
 * authorization remain external.
 */
final class AuditExportService
{
    private const MAX_ENTRIES = 500;

    public function __construct(
        private readonly AuditHistoryService $history,
        private readonly TenantAccess $access,
    ) {}

    /**
     * Returns ciphertext only, never plaintext. The caller must arrange
     * separately approved secure storage and retention before a live export.
     */
    public function prepare(
        User $actor, string $companyId, array $filters, string $reason,
        ?string $requestId = null,
    ): array {
        $this->authorize($actor, $companyId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300
            || preg_match('/[<>[:cntrl:]]/u', $reason)) {
            throw new InvalidArgumentException('A safe audit export reason is required.');
        }

        $start = $this->date($filters['fromDate'] ?? null);
        $end = $this->date($filters['toDate'] ?? null);
        if ($start > $end || (int) $start->diff($end)->format('%a') > 30) {
            throw new InvalidArgumentException('Audit export window must be at most 31 days.');
        }
        // Reuse the same strict filter validation and actor authorization as
        // owner-only audit search before minting an idempotency fingerprint.
        $this->history->search($actor, $companyId, $filters, 1, 0);
        $requestId ??= (string) Str::uuid();
        if (! Str::isUuid($requestId)) {
            throw new InvalidArgumentException('Audit export request key must be a UUID.');
        }
        $canonicalFilters = $filters;
        ksort($canonicalFilters);
        $requestHash = hash('sha256', json_encode([
            'actorId' => (int) $actor->getKey(),
            'filters' => $canonicalFilters, 'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $actor, $companyId, $filters, $reason, $requestId, $requestHash,
        ): array {
            $this->authorize($actor, $companyId);
            // Serialize same-company requests so competing retries cannot
            // produce two different encrypted packages for one request UUID.
            $company = DB::table('companies')->where('id', $companyId)
                ->lockForUpdate()->first(['id']);
            if (! $company) {
                throw new AuthorizationException('Audit export company is not accessible.');
            }
            $existing = DB::table('audit_export_requests')->where('id', $requestId)->first();
            if ($existing) {
                if ($existing->company_id !== $companyId
                    || (int) $existing->actor_id !== (int) $actor->getKey()
                    || ! hash_equals($existing->request_hash, $requestHash)) {
                    throw new ConflictHttpException('Audit export request key has already been used.');
                }

                return [
                    'exportId' => $existing->id,
                    'entryCount' => (int) $existing->entry_count,
                    'sha256' => $existing->sha256,
                    'encryptedPayload' => $existing->encrypted_payload,
                ];
            }

            // One SELECT for the full capped result is one coherent PostgreSQL
            // MVCC snapshot, unlike multiple offset pages across concurrent writes.
            $entries = $this->history->exportSnapshot(
                $actor, $companyId, $filters, self::MAX_ENTRIES,
            );
            $snapshot = json_encode([
                'format' => 'venuedesk-audit-v1', 'exportId' => $requestId,
                'companyId' => $companyId, 'filters' => $filters,
                'exportedAt' => now()->toIso8601String(), 'entries' => $entries,
            ], JSON_THROW_ON_ERROR);
            $digest = hash('sha256', $snapshot);
            $ciphertext = Crypt::encryptString($snapshot);

            // Store ciphertext only. This internal idempotency cache is NOT
            // approved immutable off-site backup, key custody or secure delivery.
            DB::table('audit_export_requests')->insert([
                'id' => $requestId, 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'request_hash' => $requestHash,
                'entry_count' => count($entries), 'sha256' => $digest,
                'encrypted_payload' => $ciphertext, 'created_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'audit.export_prepared',
                'entity_type' => 'audit_export', 'entity_id' => $requestId,
                'before_values' => null,
                'after_values' => json_encode([
                    'entryCount' => count($entries), 'sha256' => $digest,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode([
                    'reason' => $reason,
                    'dateRange' => [$filters['fromDate'], $filters['toDate']],
                ], JSON_THROW_ON_ERROR),
            ]);

            return [
                'exportId' => $requestId, 'entryCount' => count($entries),
                'sha256' => $digest, 'encryptedPayload' => $ciphertext,
            ];
        }, 3);
    }

    /**
     * Decrypt and structurally verify an exported archive for recovery drills.
     * This is intentionally callable without a web session; possession of the
     * ciphertext and the application key is required.
     */
    public function verify(string $ciphertext, ?string $expectedSha256 = null): array
    {
        try {
            $plaintext = Crypt::decryptString($ciphertext);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            throw new InvalidArgumentException('Audit export cannot be decrypted with this application key.');
        }

        try {
            $archive = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Audit export is not valid JSON.');
        }
        if (! is_array($archive)
            || ($archive['format'] ?? null) !== 'venuedesk-audit-v1'
            || ! is_string($archive['exportId'] ?? null) || ! Str::isUuid($archive['exportId'])
            || ! is_string($archive['companyId'] ?? null) || ! Str::isUuid($archive['companyId'])
            || ! is_array($archive['entries'] ?? null) || ! array_is_list($archive['entries'])) {
            throw new InvalidArgumentException('Audit export archive structure is invalid.');
        }

        $sha256 = hash('sha256', $plaintext);
        if ($expectedSha256 !== null
            && (! preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256)
                || ! hash_equals($expectedSha256, $sha256))) {
            throw new InvalidArgumentException('Audit export checksum does not match the expected digest.');
        }

        return [
            'exportId' => $archive['exportId'],
            'companyId' => $archive['companyId'],
            'entryCount' => count($archive['entries']),
            'exportedAt' => is_string($archive['exportedAt'] ?? null) ? $archive['exportedAt'] : null,
            'sha256' => $sha256,
        ];
    }

    private function authorize(User $actor, string $companyId): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            || ! User::query()->whereKey($actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            || ! $this->access->canReadAuditHistory($actor, $companyId)) {
            throw new AuthorizationException('You cannot prepare an audit export for this company.');
        }
    }

    private function date(mixed $raw): DateTimeImmutable
    {
        if (! is_string($raw)) {
            throw new InvalidArgumentException('Audit export requires an explicit date range.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException('Audit export dates are invalid.');
        }

        return $date;
    }
}
