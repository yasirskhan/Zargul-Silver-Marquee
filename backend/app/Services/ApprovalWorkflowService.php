<?php

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A decision is a NON-APPLIED authorization proposal, not a discount/payment/refund.
 * Never mutates a booking, issued invoice, ledger, customer profile, or slot claim.
 */
final class ApprovalWorkflowService
{
    public const CATEGORIES = [
        'discount', 'refund', 'overtime_waiver', 'complimentary_service',
        'price_override', 'expense',
    ];

    private function member(User $actor, string $companyId): CompanyMembership
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->is_active || ! $actor->hasVerifiedEmail()
            || ! User::query()->whereKey($actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()) {
            throw new AuthorizationException('An active verified staff session is required.');
        }
        $membership = CompanyMembership::query()
            ->where('company_id', $companyId)->where('user_id', $actor->getKey())
            ->where('is_active', true)
            ->whereHas('company', fn ($q) => $q->where('is_active', true))->first();
        if (! $membership) {
            throw new AuthorizationException('Company access has been removed.');
        }

        return $membership;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        // Internal operational justification only. Reject markup, contacts and URLs
        // instead of accidentally copying them into immutable records.
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $reason)) {
            throw new InvalidArgumentException('Enter a safe plain-text reason without contact information or links.');
        }

        return $reason;
    }

    private function category(string $category): void
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Invalid exception category.');
        }
    }

    private function amount(int $amount): void
    {
        if ($amount < 1 || $amount > 100000000000) {
            throw new InvalidArgumentException('Enter a positive bounded proposed minor-unit amount.');
        }
    }

    /**
     * Request key is caller-chosen UUID, unique across tenants. Retries of an
     * identical request are safe; different payloads cannot reuse the key.
     */
    public function request(
        User $actor, string $companyId, string $requestId, string $category,
        int $amountMinor, string $currency, ?string $bookingId, string $reason,
    ): array {
        $membership = $this->member($actor, $companyId);
        if (! in_array($membership->role, ['owner', 'admin', 'manager'], true)) {
            throw new AuthorizationException('This role cannot request exceptions.');
        }
        $this->category($category);
        $this->amount($amountMinor);
        $reason = $this->reason($reason);
        if (! Str::isUuid($requestId) || ! preg_match('/^[A-Z]{3}$/D', $currency)
            || ($bookingId !== null && ! Str::isUuid($bookingId))) {
            throw new InvalidArgumentException('Invalid request ID, currency or proposal link.');
        }
        if ($category !== 'expense' && $bookingId === null) {
            throw new InvalidArgumentException('A linked internal proposal is required for this category.');
        }
        if ($category === 'expense' && $bookingId !== null) {
            throw new InvalidArgumentException('An expense cannot be disguised as a booking adjustment.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $requestId, $category, $amountMinor, $currency, $bookingId, $reason
        ): array {
            $membership = $this->member($actor, $companyId);
            if (! in_array($membership->role, ['owner', 'admin', 'manager'], true)) {
                throw new AuthorizationException('This role cannot request exceptions.');
            }
            if ($bookingId !== null) {
                $booking = DB::table('booking_drafts')
                    ->where('company_id', $companyId)->where('id', $bookingId)->first();
                if (! $booking || $booking->status !== 'under_review') {
                    throw new ConflictHttpException('This proposal is unavailable for exception review.');
                }
                if ($membership->role === 'manager'
                    && ! DB::table('hall_assignments')->where('company_id', $companyId)
                        ->where('membership_id', $membership->id)
                        ->where('hall_id', $booking->hall_id)->exists()) {
                    throw new AuthorizationException('Manager has no assignment to this hall.');
                }
            } elseif ($membership->role === 'manager') {
                throw new AuthorizationException('Managers cannot initiate company-wide expenses.');
            }

            $existing = DB::table('approval_requests')->where('id', $requestId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->company_id === $companyId
                    && (int) $existing->requested_by === (int) $actor->getKey()
                    && $existing->category === $category
                    && (int) $existing->amount_minor === $amountMinor
                    && $existing->currency === $currency && $existing->booking_id === $bookingId
                    && $existing->reason === $reason) {
                    return $this->summary($existing);
                }
                throw new ConflictHttpException('This approval request key is already used.');
            }
            // INSERT .. ON CONFLICT DO NOTHING supports concurrent identical retries.
            $inserted = DB::table('approval_requests')->insertOrIgnore([
                'id' => $requestId, 'company_id' => $companyId, 'booking_id' => $bookingId,
                'category' => $category, 'amount_minor' => $amountMinor, 'currency' => $currency,
                'requested_by' => $actor->getKey(), 'reason' => $reason,
                'status' => 'pending', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $inserted) {
                $raced = DB::table('approval_requests')->where('id', $requestId)->first();
                if ($raced && $raced->company_id === $companyId
                    && (int) $raced->requested_by === (int) $actor->getKey()
                    && $raced->category === $category && (int) $raced->amount_minor === $amountMinor
                    && $raced->currency === $currency && $raced->booking_id === $bookingId
                    && $raced->reason === $reason) {
                    return $this->summary($raced);
                }
                throw new ConflictHttpException('Approval request key collision.');
            }

            $this->audit($actor, $companyId, 'approval.requested', 'approval_request',
                $requestId, null, ['status' => 'pending', 'category' => $category, 'amountMinor' => $amountMinor,
                    'currency' => $currency, 'bookingId' => $bookingId], $reason);
            $this->alert($companyId, $requestId, 'requested');

            return ['id' => $requestId, 'status' => 'pending', 'revision' => 1];
        }, 3);
    }

    public function decide(
        User $actor, string $companyId, string $requestId,
        int $expectedRevision, string $decision, string $reason,
    ): array {
        $this->member($actor, $companyId);
        $reason = $this->reason($reason);
        if (! Str::isUuid($requestId) || $expectedRevision < 1
            || ! in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid decision or expected revision.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $requestId, $expectedRevision, $decision, $reason
        ): array {
            $membership = $this->member($actor, $companyId);
            $request = DB::table('approval_requests')->where('company_id', $companyId)
                ->where('id', $requestId)->lockForUpdate()->first();
            if (! $request) {
                throw new AuthorizationException('Approval request unavailable.');
            }
            if ($request->status !== 'pending' || (int) $request->revision !== $expectedRevision) {
                // A lost HTTP response may retry the identical already-committed
                // decision. Return its stored result, never write a second event
                // or permit a different actor, action, reason or revision.
                if (in_array($membership->role, ['owner', 'admin'], true)
                    && $request->status === $decision
                    && (int) $request->revision === $expectedRevision + 1
                    && DB::table('approval_decisions')->where('company_id', $companyId)
                        ->where('approval_id', $requestId)
                        ->where('decided_by', $actor->getKey())
                        ->where('decision', $decision)->where('reason', $reason)->exists()) {
                    return ['id' => $requestId, 'status' => $decision,
                        'revision' => $expectedRevision + 1];
                }
                throw new ConflictHttpException('This request is no longer pending at the selected revision.');
            }
            if ((int) $request->requested_by === (int) $actor->getKey()) {
                throw new AuthorizationException('Self-approval and self-rejection are prohibited.');
            }
            $delegationId = null;
            if ($membership->role !== 'owner') {
                if ($membership->role !== 'admin') {
                    throw new AuthorizationException('Only an owner or eligible delegated administrator can decide.');
                }
                // Protect revocation races: lock the delegation before considering
                // time, category, amount, and the *currently active* membership.
                $grants = DB::table('approval_delegations')
                    ->where('company_id', $companyId)->where('delegated_to', $actor->getKey())
                    ->whereNull('revoked_at')->orderBy('id')->lockForUpdate()->get();
                foreach ($grants as $grant) {
                    $now = now();
                    if ($now->gte($grant->starts_at) && $now->lt($grant->ends_at)
                        && (int) $grant->ceiling_minor >= (int) $request->amount_minor
                        && in_array($request->category, json_decode($grant->categories, true, 512, JSON_THROW_ON_ERROR), true)
                        && DB::table('company_memberships')->where('company_id', $companyId)
                            ->where('user_id', $grant->granted_by)->where('role', 'owner')
                            ->where('is_active', true)->exists()) {
                        $delegationId = $grant->id;
                        break;
                    }
                }
                if (! $delegationId) {
                    throw new AuthorizationException('No active emergency grant permits this decision.');
                }
            }

            DB::table('approval_requests')->where('company_id', $companyId)
                ->where('id', $requestId)->update([
                    'status' => $decision, 'revision' => $expectedRevision + 1, 'updated_at' => now(),
                ]);
            DB::table('approval_decisions')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'approval_id' => $requestId, 'decided_by' => $actor->getKey(),
                'delegation_id' => $delegationId, 'decision' => $decision, 'reason' => $reason,
            ]);
            $this->audit($actor, $companyId, 'approval.'.$decision, 'approval_request',
                $requestId, ['status' => 'pending', 'revision' => $expectedRevision],
                ['status' => $decision, 'revision' => $expectedRevision + 1,
                    'delegationId' => $delegationId], $reason);
            $this->alert($companyId, $requestId, 'decided');

            return ['id' => $requestId, 'status' => $decision, 'revision' => $expectedRevision + 1];
        }, 3);
    }

    public function grant(
        User $owner, string $companyId, string $delegationId, int $adminId,
        array $categories, int $ceilingMinor, string $startsAt, string $endsAt,
        string $reason,
    ): array {
        $member = $this->member($owner, $companyId);
        if ($member->role !== 'owner') {
            throw new AuthorizationException('Only an active owner can grant emergency authority.');
        }
        $this->amount($ceilingMinor);
        $reason = $this->reason($reason);
        $categories = array_values(array_unique($categories));
        if (! Str::isUuid($delegationId) || $adminId === (int) $owner->getKey()
            || ! $categories || count($categories) > 6) {
            throw new InvalidArgumentException('Invalid emergency delegation.');
        }
        foreach ($categories as $category) {
            if (! is_string($category)) {
                throw new InvalidArgumentException('Delegation categories must be strings.');
            }
            $this->category($category);
        }
        try {
            $start = CarbonImmutable::parse($startsAt);
            $end = CarbonImmutable::parse($endsAt);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid delegation start or end time.');
        }
        // Human datetime-local values are minute-precision and API ISO values may be
        // second-precision. Allow at most one minute of clock/rounding skew,
        // never a backdated or indefinite grant.
        if ($start->lt(now()->subMinute()) || $end->lte($start) || $end->gt($start->addDays(7))) {
            throw new InvalidArgumentException('Emergency grants must start now or later and last at most seven days.');
        }

        return DB::transaction(function () use (
            $owner, $companyId, $delegationId, $adminId, $categories,
            $ceilingMinor, $start, $end, $reason
        ): array {
            if ($this->member($owner, $companyId)->role !== 'owner'
                || ! CompanyMembership::query()->where('company_id', $companyId)
                    ->where('user_id', $adminId)->where('role', 'admin')->where('is_active', true)
                    ->whereHas('user', fn ($q) => $q->where('is_active', true)
                        ->whereNotNull('email_verified_at'))->exists()) {
                throw new AuthorizationException('Grantor or delegated administrator is not eligible.');
            }
            if (DB::table('approval_delegations')->where('id', $delegationId)->exists()) {
                throw new ConflictHttpException('Delegation key already exists.');
            }
            DB::table('approval_delegations')->insert([
                'id' => $delegationId, 'company_id' => $companyId,
                'granted_by' => $owner->getKey(), 'delegated_to' => $adminId,
                'categories' => json_encode($categories, JSON_THROW_ON_ERROR),
                'ceiling_minor' => $ceilingMinor, 'starts_at' => $start, 'ends_at' => $end,
                'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->delegationEvent($owner, $companyId, $delegationId, 'granted', $reason);
            $this->audit($owner, $companyId, 'approval.delegation_granted', 'approval_delegation',
                $delegationId, null, ['adminId' => $adminId, 'categories' => $categories,
                    'ceilingMinor' => $ceilingMinor, 'startsAt' => $start->toIso8601String(),
                    'endsAt' => $end->toIso8601String()], $reason);

            return ['id' => $delegationId, 'status' => 'active'];
        }, 3);
    }

    public function revoke(User $owner, string $companyId, string $delegationId, string $reason): void
    {
        if ($this->member($owner, $companyId)->role !== 'owner') {
            throw new AuthorizationException('Only an owner can revoke emergency authority.');
        }
        $reason = $this->reason($reason);
        if (! Str::isUuid($delegationId)) {
            throw new InvalidArgumentException('Invalid delegation ID.');
        }
        DB::transaction(function () use ($owner, $companyId, $delegationId, $reason): void {
            if ($this->member($owner, $companyId)->role !== 'owner') {
                throw new AuthorizationException('Owner authority has been revoked.');
            }
            $grant = DB::table('approval_delegations')->where('company_id', $companyId)
                ->where('id', $delegationId)->lockForUpdate()->first();
            if (! $grant) {
                throw new AuthorizationException('Delegation unavailable.');
            }
            if ($grant->revoked_at !== null) {
                throw new ConflictHttpException('Delegation already revoked.');
            }
            DB::table('approval_delegations')->where('id', $delegationId)
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            $this->delegationEvent($owner, $companyId, $delegationId, 'revoked', $reason);
            $this->audit($owner, $companyId, 'approval.delegation_revoked', 'approval_delegation',
                $delegationId, ['active' => true], ['active' => false], $reason);
        }, 3);
    }

    /** Tenant-authorized inbox: company-scoped staff names and non-PII proposal metadata; no customer data. */
    public function inbox(User $actor, string $companyId, int $limit = 30, ?string $referenceId = null): array
    {
        $membership = $this->member($actor, $companyId);
        if (! in_array($membership->role, ['owner', 'admin', 'manager'], true)
            || $limit < 1 || $limit > 50) {
            throw new AuthorizationException('Approval inbox is not accessible.');
        }
        if ($referenceId !== null && ! Str::isUuid($referenceId)) {
            throw new InvalidArgumentException('Invalid approval reference.');
        }
        $q = DB::table('approval_requests as req')
            ->leftJoin('users as requester', 'requester.id', '=', 'req.requested_by')
            ->leftJoin('booking_drafts as booking', function ($join): void {
                $join->on('booking.id', '=', 'req.booking_id')
                    ->on('booking.company_id', '=', 'req.company_id');
            })
            ->leftJoin('halls as hall', function ($join): void {
                $join->on('hall.id', '=', 'booking.hall_id')
                    ->on('hall.company_id', '=', 'req.company_id');
            })->where('req.company_id', $companyId);
        if ($referenceId !== null) {
            $q->where('req.id', $referenceId);
        }
        if ($membership->role === 'manager') {
            $q->where('req.requested_by', $actor->getKey());
        }

        return $q->orderByDesc('req.created_at')->orderByDesc('req.id')->limit($limit)
            ->get(['req.id', 'req.category', 'req.status', 'req.revision', 'req.amount_minor',
                'req.currency', 'req.reason', 'req.booking_id', 'req.requested_by', 'req.created_at',
                'requester.name as requester_name', 'booking.event_date as proposed_date', 'booking.program as proposed_program',
                'hall.name as proposed_hall'])
            ->map(fn ($row) => array_merge($this->summary($row), [
                'requesterName' => (string) ($row->requester_name ?? ''),
                'requestedAt' => (string) $row->created_at,
                'proposal' => $row->proposed_date !== null && $row->proposed_hall !== null
                    ? ['date' => $row->proposed_date, 'program' => $row->proposed_program,
                        'hallName' => $row->proposed_hall] : null,
            ]))->all();
    }

    /**
     * First protected-panel visit: private, aggregate-only owner notices.
     * A requester's own proposal is never represented as an actionable item.
     * No raw customer, reason, token, or financial document enters the shell.
     */
    public function ownerPendingAttention(User $actor): array
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->is_active || ! $actor->hasVerifiedEmail()
            || ! User::query()->whereKey($actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()) {
            throw new AuthorizationException('An active verified owner session is required.');
        }

        $rows = DB::table('approval_requests as req')
            ->join('company_memberships as member', function ($join): void {
                $join->on('member.company_id', '=', 'req.company_id')
                    ->where('member.role', '=', 'owner')
                    ->where('member.is_active', '=', true);
            })
            ->join('companies as company', function ($join): void {
                $join->on('company.id', '=', 'req.company_id')
                    ->where('company.is_active', '=', true);
            })
            ->where('member.user_id', $actor->getKey())
            ->where('req.status', 'pending')
            ->where('req.requested_by', '<>', $actor->getKey())
            ->groupBy('req.company_id', 'company.name')
            ->orderBy('req.company_id')
            ->limit(25)
            ->get([
                'req.company_id', 'company.name',
                DB::raw('COUNT(*) AS pending_count'),
                DB::raw('MAX(req.created_at) AS newest_request'),
                DB::raw('MAX(req.id::text) AS newest_id'),
            ]);

        $items = $rows->map(fn ($row) => [
            'companyId' => $row->company_id, 'company' => $row->name,
            'pending' => (int) $row->pending_count,
        ])->all();
        $fingerprint = $rows->isEmpty() ? '' : hash('sha256',
            $rows->map(fn ($row) => implode(':', [
                $row->company_id, $row->pending_count, $row->newest_request, $row->newest_id,
            ]))->implode('|'));

        return ['items' => $items, 'fingerprint' => $fingerprint];
    }

    /** Current authorized owner-only emergency grants, never expired grants. */
    public function activeDelegations(User $actor, string $companyId, int $limit = 30): array
    {
        if ($this->member($actor, $companyId)->role !== 'owner') {
            throw new AuthorizationException('Only an active company owner can view emergency grants.');
        }
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Invalid delegation history page size.');
        }

        return DB::table('approval_delegations')
            ->where('company_id', $companyId)->whereNull('revoked_at')
            ->where('ends_at', '>', now())
            ->orderBy('ends_at')->orderBy('id')->limit($limit)
            ->get(['id', 'delegated_to', 'ceiling_minor', 'starts_at', 'ends_at'])
            ->map(fn ($row) => (array) $row)->all();
    }

    private function summary(object $row): array
    {
        return ['id' => $row->id, 'status' => $row->status, 'revision' => (int) $row->revision,
            'category' => $row->category, 'amountMinor' => (int) $row->amount_minor,
            'currency' => $row->currency, 'bookingId' => $row->booking_id,
            'requestedBy' => (int) $row->requested_by, 'reason' => $row->reason];
    }

    private function delegationEvent(
        User $actor, string $companyId, string $grantId, string $action, string $reason,
    ): void {
        DB::table('approval_delegation_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'delegation_id' => $grantId, 'actor_id' => $actor->getKey(),
            'action' => $action, 'reason' => $reason,
        ]);
    }

    private function alert(string $companyId, string $requestId, string $event): void
    {
        DB::table('approval_alert_outbox')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'approval_id' => $requestId, 'event_type' => $event,
        ]);
    }

    private function audit(
        User $actor, string $companyId, string $action, string $type,
        string $entityId, ?array $before, array $after, string $reason,
    ): void {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(), 'action' => $action, 'entity_type' => $type,
            'entity_id' => $entityId,
            'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }
}
