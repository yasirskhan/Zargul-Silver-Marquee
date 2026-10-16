<?php

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Private owner/admin search: no contacts, financial postings or free-form audit payloads. */
final class InternalQuickSearchService
{
    private const SOURCES = [
        ['kind' => 'proposal', 'table' => 'booking_drafts',
            'label' => 'program', 'status' => true,
            'fields' => ['id::text', 'program', 'event_date::text'],
            'path' => '/admin/booking-proposals'],
        ['kind' => 'planning', 'table' => 'operations_plan_tasks',
            'label' => 'title', 'status' => true,
            'fields' => ['id::text', 'title'],
            'path' => '/admin/operations-planning'],
        ['kind' => 'approval', 'table' => 'approval_requests',
            'label' => 'category', 'status' => true,
            'fields' => ['id::text', 'category'],
            'path' => '/admin/approval-center'],
        ['kind' => 'finance', 'table' => 'finance_quote_previews',
            'label' => 'program', 'status' => false,
            'fields' => ['id::text', 'program'],
            'path' => '/admin/finance-review'],
        ['kind' => 'audit', 'table' => 'audit_events',
            'label' => 'action', 'status' => false,
            'fields' => ['id::text', 'action', 'entity_type'],
            'path' => '/admin/audit-history'],
    ];

    public function search(User $actor, string $companyId, string $term, int $limit = 20): array
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->is_active || ! $actor->hasVerifiedEmail()
            || ! CompanyMembership::query()->where('company_id', $companyId)
                ->where('user_id', $actor->getKey())->where('is_active', true)
                ->whereIn('role', ['owner', 'admin'])
                ->whereHas('company', fn ($q) => $q->where('is_active', true))->exists()) {
            throw new AuthorizationException('An active verified owner/admin session is required.');
        }
        $term = trim($term);
        if (mb_strlen($term) < 3 || mb_strlen($term) > 80 || $limit < 1 || $limit > 40
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $term)) {
            throw new InvalidArgumentException('Use 3-80 plain characters; contact details are prohibited.');
        }
        $matches = [];
        foreach (self::SOURCES as $source) {
            $query = DB::table($source['table'])->where('company_id', $companyId)
                ->where(function ($query) use ($source, $term): void {
                    foreach ($source['fields'] as $field) {
                        // Expressions come exclusively from immutable server constants.
                        $query->orWhereRaw("strpos(lower($field), lower(?)) > 0", [$term]);
                    }
                });
            $columns = ['id', $source['label'].' as result_label'];
            if ($source['status']) {
                $columns[] = 'status';
            }
            foreach ($query->orderBy('id')->limit(min(10, $limit))->get($columns) as $row) {
                $matches[] = [
                    'kind' => $source['kind'], 'id' => $row->id,
                    'label' => $row->result_label,
                    'status' => $source['status'] ? $row->status : 'internal',
                    'path' => $source['path'],
                ];
            }
        }
        return array_slice($matches, 0, $limit);
    }
}
