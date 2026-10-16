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

/**
 * Internal encrypted audit export foundation. No HTTP/download route,
 * external backup, retention guarantee or operator-approved key procedure.
 */
final class AuditExportService
{
    private const PAGE_SIZE = 100;
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
    ): array {
        $this->authorize($actor, $companyId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300
            || preg_match('/[<>[:cntrl:]]/u', $reason)) {
            throw new InvalidArgumentException('A safe audit export reason is required.');
        }

        // Require an explicit small date window for expensive private exports.
        $start = $this->date($filters['fromDate'] ?? null);
        $end = $this->date($filters['toDate'] ?? null);
        if ($start > $end || (int) $start->diff($end)->format('%a') > 30) {
            throw new InvalidArgumentException('Audit export window must be at most 31 days.');
        }

        return DB::transaction(function () use ($actor, $companyId, $filters, $reason): array {
            $this->authorize($actor, $companyId);
            $entries = [];
            for ($offset = 0; $offset <= self::MAX_ENTRIES; $offset += self::PAGE_SIZE) {
                // Underlying query rechecks company/role and validates all filters.
                $page = $this->history->search($actor, $companyId, $filters, self::PAGE_SIZE, $offset);
                $entries = array_merge($entries, $page['entries']);
                if (count($entries) > self::MAX_ENTRIES) {
                    throw new InvalidArgumentException('Audit export exceeds the protected row limit.');
                }
                if (! $page['hasMore']) {
                    break;
                }
            }
            $id = (string) Str::uuid();
            $snapshot = json_encode([
                'format' => 'venuedesk-audit-v1', 'exportId' => $id,
                'companyId' => $companyId, 'filters' => $filters,
                'exportedAt' => now()->toIso8601String(), 'entries' => $entries,
            ], JSON_THROW_ON_ERROR);
            $digest = hash('sha256', $snapshot);
            $ciphertext = Crypt::encryptString($snapshot);

            // Log who prepared the encrypted package. No event details in the log.
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'audit.export_prepared',
                'entity_type' => 'audit_export', 'entity_id' => $id,
                'before_values' => null,
                'after_values' => json_encode(['entryCount' => count($entries), 'sha256' => $digest], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason, 'dateRange' => [$filters['fromDate'], $filters['toDate']]], JSON_THROW_ON_ERROR),
            ]);

            return [
                'exportId' => $id, 'entryCount' => count($entries),
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
