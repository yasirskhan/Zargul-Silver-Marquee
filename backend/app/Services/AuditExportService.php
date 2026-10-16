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
 * Internal encrypted audit export foundation. No HTTP/download route,
 * external backup, retention guarantee or operator-approved key procedure.
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

    private function authorize(User $actor, string $companyId): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
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
