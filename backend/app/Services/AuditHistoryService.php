<?php

namespace App\Services;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal authorized and bounded audit search. No public or delegated
 * manager endpoint. Raw history is append-only; this service NEVER edits it.
 */
final class AuditHistoryService
{
    public function __construct(private readonly TenantAccess $access) {}

    /**
     * Filters: action, entityType, entityId, fromDate, toDate.
     * Pagination is bounded and deliberately simple; export and durable
     * keyset pagination require a later protected release gate.
     */
    public function search(
        User $actor, string $companyId, array $filters = [],
        int $limit = 25, int $offset = 0,
    ): array {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            || ! User::query()->whereKey($actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            || ! $this->access->canReadAuditHistory($actor, $companyId)) {
            throw new AuthorizationException('You cannot search audit history for this company.');
        }

        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 5000
            || array_diff(array_keys($filters),
                ['action', 'entityType', 'entityId', 'fromDate', 'toDate'])) {
            throw new InvalidArgumentException('Audit search filters or page size are invalid.');
        }

        foreach (['action' => 120, 'entityType' => 64, 'entityId' => 80] as $field => $max) {
            if (array_key_exists($field, $filters) &&
                (! is_string($filters[$field]) || mb_strlen($filters[$field]) > $max
                    || ! preg_match('/\A[a-zA-Z0-9._:-]+\z/D', $filters[$field]))) {
                throw new InvalidArgumentException('Audit filter format is invalid.');
            }
        }
        foreach (['fromDate', 'toDate'] as $field) {
            if (array_key_exists($field, $filters)) {
                if (! is_string($filters[$field])) {
                    throw new InvalidArgumentException('Audit date filter is invalid.');
                }
                $value = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$field]);
                if ($value === false || $value->format('Y-m-d') !== $filters[$field]) {
                    throw new InvalidArgumentException('Audit date filter is invalid.');
                }
            }
        }
        if (isset($filters['fromDate'], $filters['toDate'])
            && $filters['fromDate'] > $filters['toDate']) {
            throw new InvalidArgumentException('Audit date range is reversed.');
        }

        $query = DB::table('audit_events')->where('company_id', $companyId);
        foreach (['action' => 'action', 'entityType' => 'entity_type', 'entityId' => 'entity_id'] as $field => $column) {
            if (isset($filters[$field])) {
                $query->where($column, $filters[$field]);
            }
        }
        if (isset($filters['fromDate'])) {
            $query->whereDate('created_at', '>=', $filters['fromDate']);
        }
        if (isset($filters['toDate'])) {
            $query->whereDate('created_at', '<=', $filters['toDate']);
        }

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')
            ->offset($offset)->limit($limit + 1)
            ->get([
                'id', 'action', 'entity_type', 'entity_id', 'actor_id',
                'before_values', 'after_values', 'context', 'created_at',
            ]);
        $hasMore = $rows->count() > $limit;

        return [
            'entries' => $rows->take($limit)->map(fn (object $record): array => [
                'id' => $record->id, 'action' => $record->action,
                'entityType' => $record->entity_type, 'entityId' => $record->entity_id,
                'actorId' => $record->actor_id,
                'before' => $this->decode($record->before_values),
                'after' => $this->decode($record->after_values),
                'context' => $this->decode($record->context),
                'createdAt' => $record->created_at,
            ])->values()->all(),
            'hasMore' => $hasMore, 'limit' => $limit, 'offset' => $offset,
        ];
    }

    /**
     * Export-safe bounded projection. Unlike repeated offset queries, the full
     * result set is obtained in ONE PostgreSQL SELECT (one MVCC snapshot),
     * avoiding missing/duplicated rows when concurrent audit events arrive.
     * This is internal only; it does not expose a download endpoint.
     */
    public function exportSnapshot(
        User $actor, string $companyId, array $filters, int $maxEntries = 500,
    ): array {
        if ($maxEntries < 1 || $maxEntries > 500) {
            throw new InvalidArgumentException('Audit export row limit is invalid.');
        }

        // Reuse existing authorization and strict input validation.
        $this->search($actor, $companyId, $filters, 1, 0);

        $query = DB::table('audit_events')->where('company_id', $companyId);
        foreach (['action' => 'action', 'entityType' => 'entity_type', 'entityId' => 'entity_id'] as $field => $column) {
            if (isset($filters[$field])) {
                $query->where($column, $filters[$field]);
            }
        }
        if (isset($filters['fromDate'])) {
            $query->whereDate('created_at', '>=', $filters['fromDate']);
        }
        if (isset($filters['toDate'])) {
            $query->whereDate('created_at', '<=', $filters['toDate']);
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')
            ->limit($maxEntries + 1)->get([
                'id', 'action', 'entity_type', 'entity_id', 'actor_id',
                'before_values', 'after_values', 'context', 'created_at',
            ]);
        if ($rows->count() > $maxEntries) {
            throw new InvalidArgumentException('Audit export exceeds the protected row limit.');
        }

        return $rows->map(fn (object $record): array => [
            'id' => $record->id, 'action' => $record->action,
            'entityType' => $record->entity_type, 'entityId' => $record->entity_id,
            'actorId' => $record->actor_id,
            'before' => $this->decode($record->before_values),
            'after' => $this->decode($record->after_values),
            'context' => $this->decode($record->context),
            'createdAt' => $record->created_at,
        ])->values()->all();
    }

    private function decode(?string $json): mixed
    {
        return $json === null ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
