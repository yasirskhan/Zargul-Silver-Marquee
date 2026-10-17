<?php

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Staff search: assigned-hall planning and manager's own approvals; owner/admin private sources. */
final class InternalQuickSearchService
{
    private const SOURCES = [
        ['kind' => 'proposal', 'table' => 'booking_drafts',
            'label' => 'program', 'status' => true,
            'fields' => ['id::text', 'program', 'event_date::text', 'status'],
            'path' => '/admin/booking-proposals'],
        ['kind' => 'planning', 'table' => 'operations_plan_tasks',
            'label' => 'title', 'status' => true,
            'fields' => ['id::text', 'title', 'status'],
            'path' => '/admin/operations-planning'],
        ['kind' => 'approval', 'table' => 'approval_requests',
            'label' => 'category', 'status' => true,
            // Internal owner/manager may look up a safe opaque booking ID to
            // find its already-scoped approval proposals, not customer data.
            'fields' => ['id::text', 'booking_id::text', 'category', 'status'],
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

    public function search(User $actor, string $companyId, string $term, int $limit = 20, string $kind = 'all'): array
    {
        $membership = CompanyMembership::query()->where('company_id', $companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin', 'manager', 'operations'])
            ->whereHas('company', fn ($q) => $q->where('is_active', true))->first();
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->is_active || ! $actor->hasVerifiedEmail()
            || ! User::query()->whereKey($actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            || ! $membership) {
            throw new AuthorizationException('An active verified company staff session is required.');
        }
        $restricted = in_array($membership->role, ['manager', 'operations'], true);
        if (! in_array($kind, ['all', 'proposal', 'planning', 'approval', 'finance', 'audit'], true)) {
            throw new InvalidArgumentException('Unknown internal record type.');
        }
        $allowedKinds = $membership->role === 'manager'
            ? ['all', 'planning', 'approval'] : ['all', 'planning'];
        if ($restricted && ! in_array($kind, $allowedKinds, true)) {
            throw new AuthorizationException('This record category is not permitted for assigned staff.');
        }
        $term = trim($term);
        if (mb_strlen($term) < 3 || mb_strlen($term) > 80 || $limit < 1 || $limit > 40
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $term)) {
            throw new InvalidArgumentException('Use 3-80 plain characters; contact details are prohibited.');
        }
        // Audit History permits both verified owner and admin; search uses
        // the same active company membership boundary as that page.
        $matches = [];
        foreach (self::SOURCES as $source) {
            if ($kind !== 'all' && $source['kind'] !== $kind) {
                continue;
            }
            // Match destination policies exactly: operations may only find
            // assigned planning; managers may also find THEIR OWN approvals.
            // No staff proposal, finance or audit record is searchable.
            if ($restricted && ! in_array($source['kind'], $membership->role === 'manager'
                ? ['planning', 'approval'] : ['planning'], true)) {
                continue;
            }
            $query = DB::table($source['table'])->where('company_id', $companyId)
                ->where(function ($query) use ($source, $term): void {
                    foreach ($source['fields'] as $field) {
                        // Expressions come exclusively from immutable server constants.
                        $query->orWhereRaw("strpos(lower($field), lower(?)) > 0", [$term]);
                    }
                });
            if ($restricted && $source['kind'] === 'approval') {
                // ApprovalWorkflowService::inbox grants managers only their
                // own request history, regardless of approval outcome.
                $query->where('requested_by', $actor->getKey());
            }
            if ($restricted && $source['kind'] === 'planning') {
                // Enforce hall assignment in PostgreSQL, never in a client filter.
                // Removing the assignment immediately removes its search results.
                $query->whereExists(function ($scope) use ($companyId, $membership): void {
                    $scope->selectRaw('1')->from('hall_assignments')
                        ->where('hall_assignments.company_id', $companyId)
                        ->where('hall_assignments.membership_id', $membership->getKey())
                        ->whereColumn('hall_assignments.hall_id', 'operations_plan_tasks.hall_id');
                });
            }
            $columns = ['id', $source['label'].' as result_label'];
            if ($source['status']) {
                $columns[] = 'status';
            }
            if ($source['kind'] === 'planning') {
                $columns[] = 'booking_id';
            }
            foreach ($query->orderBy('id')->limit(min(10, $limit))->get($columns) as $row) {
                // The planning workspace only accepts exact active proposal links.
                // Preserve access to historical/withdrawn task search by falling
                // back to the company's generic planning worklist.
                $planningProposal = $source['kind'] === 'planning'
                    && DB::table('booking_drafts')->where('company_id', $companyId)
                        ->where('id', $row->booking_id)
                        ->whereIn('status', ['draft', 'under_review'])->exists()
                    ? $row->booking_id : null;
                $matches[] = [
                    'kind' => $source['kind'], 'id' => $row->id,
                    'label' => $row->result_label,
                    'status' => $source['status'] ? $row->status : 'internal',
                    'path' => $source['path'].'?company='.rawurlencode($companyId)
                        .($source['kind'] === 'proposal'
                            ? '&proposal='.rawurlencode($row->id) : '')
                        .($planningProposal !== null
                            ? '&proposal='.rawurlencode($planningProposal) : '')
                        .($source['kind'] === 'approval'
                            ? '&request='.rawurlencode($row->id) : '')
                        .($source['kind'] === 'finance'
                            ? '&preview='.rawurlencode($row->id) : ''),
                ];
            }
        }
        return array_slice($matches, 0, $limit);
    }
}
