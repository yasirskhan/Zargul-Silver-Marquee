<?php

namespace App\Services;

use App\Policies\FinanceAccessPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class FinanceControlService
{
    private function authorize(User $actor, string $companyId, bool $ownerOnly = false): void
    {
        $policy = new FinanceAccessPolicy();
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! ($ownerOnly ? $policy->post($actor, $companyId) : $policy->view($actor, $companyId))) {
            throw new AuthorizationException('Owner or authorized company financial access is required.');
        }
    }

    private function reason(string $input): string
    {
        $input = trim($input);
        if (mb_strlen($input) < 3 || mb_strlen($input) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $input)) {
            throw new InvalidArgumentException('Use a plain-text operational reason without private contact details.');
        }
        return $input;
    }

    private function currency(string $code): void
    {
        if (! preg_match('/^[A-Z]{3}$/D', $code)) {
            throw new InvalidArgumentException('A three-letter ISO currency code is required.');
        }
    }

    private function postingEnabled(): void
    {
        if (config('finance_controls.posting_enabled') !== true) {
            throw new ConflictHttpException('Real ledger posting is disabled until finance release controls are approved.');
        }
    }

    /** Frozen non-binding hall rental price, without addons/tax/client contact. */
    public function preview(
        User $actor, string $companyId, string $previewId, string $bookingId,
        int $expectedBookingRevision, string $reason,
    ): array {
        $this->authorize($actor, $companyId);
        $reason = $this->reason($reason);
        if (! Str::isUuid($previewId) || ! Str::isUuid($bookingId) || $expectedBookingRevision < 1) {
            throw new InvalidArgumentException('Valid internal proposal and revision are required.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $previewId, $bookingId, $expectedBookingRevision, $reason
        ): array {
            $this->authorize($actor, $companyId);
            $existing = DB::table('finance_quote_previews')->where('id', $previewId)->first();
            if ($existing) {
                if ($existing->company_id === $companyId
                    && (int) $existing->created_by === (int) $actor->getKey()
                    && $existing->booking_id === $bookingId
                    && (int) $existing->booking_revision === $expectedBookingRevision
                    && $existing->reason === $reason) {
                    return $this->previewSummary($existing);
                }
                throw new ConflictHttpException('This preview key is already assigned.');
            }

            $booking = DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $bookingId)->lockForUpdate()->first();
            if (! $booking || $booking->status !== 'under_review'
                || (int) $booking->revision !== $expectedBookingRevision) {
                throw new ConflictHttpException('Proposal is not in the exact review revision.');
            }
            $hall = DB::table('halls')->where('company_id', $companyId)
                ->where('id', $booking->hall_id)->where('is_active', true)
                ->lockForUpdate()->first();
            if (! $hall || (int) $hall->program_rules_revision < 1) {
                throw new ConflictHttpException('Approved hall rental price configuration is missing.');
            }
            $rules = DB::table('hall_program_rule_revisions')
                ->where('company_id', $companyId)->where('hall_id', $hall->id)
                ->where('revision_number', $hall->program_rules_revision)->first();
            if (! $rules) {
                throw new ConflictHttpException('Current approved hall program revision is missing.');
            }
            $programs = json_decode($rules->rules, true, 512, JSON_THROW_ON_ERROR);
            $price = $programs[$booking->program]['rentalRateMinor'] ?? null;
            if (! is_int($price) || $price < 0 || $price > 100000000000) {
                throw new ConflictHttpException('Frozen owner-approved rental rate is invalid.');
            }
            $this->currency($rules->currency_code);
            $lines = [[
                'code' => 'hall_rental', 'program' => $booking->program,
                'quantity' => 1, 'rateMinor' => $price, 'amountMinor' => $price,
                'sourceRulesRevision' => (int) $hall->program_rules_revision,
            ]];

            DB::table('finance_quote_previews')->insert([
                'id' => $previewId, 'company_id' => $companyId,
                'booking_id' => $bookingId, 'hall_id' => $hall->id,
                'booking_revision' => $expectedBookingRevision,
                'program_rules_revision' => $hall->program_rules_revision,
                'program' => $booking->program, 'currency_code' => $rules->currency_code,
                'frozen_lines' => json_encode($lines, JSON_THROW_ON_ERROR),
                'subtotal_minor' => $price, 'reason' => $reason,
                'created_by' => $actor->getKey(),
            ]);
            $this->audit($actor, $companyId, 'finance.preview_created', 'finance_quote_preview',
                $previewId, ['bookingId' => $bookingId, 'bookingRevision' => $expectedBookingRevision,
                    'programRuleRevision' => $hall->program_rules_revision,
                    'currency' => $rules->currency_code, 'subtotalMinor' => $price], $reason);

            return ['id' => $previewId, 'status' => 'internal_nonbinding',
                'subtotalMinor' => $price, 'currency' => $rules->currency_code,
                'sourceRevision' => (int) $hall->program_rules_revision];
        }, 3);
    }

    public function previews(User $actor, string $companyId, int $limit = 30): array
    {
        $this->authorize($actor, $companyId);
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Invalid preview page size.');
        }
        return DB::table('finance_quote_previews')->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
            ->get()->map(fn ($row) => $this->previewSummary($row))->all();
    }

    private function previewSummary(object $row): array
    {
        return ['id' => $row->id, 'status' => 'internal_nonbinding',
            'bookingId' => $row->booking_id, 'bookingRevision' => (int) $row->booking_revision,
            'subtotalMinor' => (int) $row->subtotal_minor, 'currency' => $row->currency_code,
            'sourceRevision' => (int) $row->program_rules_revision];
    }

    /**
     * Owner-controlled opening cash accounting only, no customer payments or
     * refunds. Disabled by default until finance custody release signoff.
     * Each opening has 2 balanced immutable entries; retries are exact-key.
     */
    public function openCash(
        User $owner, string $companyId, string $journalId, string $currency,
        int $amountMinor, string $reason,
    ): array {
        $this->authorize($owner, $companyId, true);
        $this->postingEnabled();
        $this->currency($currency);
        $reason = $this->reason($reason);
        if (! Str::isUuid($journalId) || $amountMinor < 1 || $amountMinor > 100000000000) {
            throw new InvalidArgumentException('Opening amount must be a bounded positive minor-unit integer.');
        }

        return DB::transaction(function () use ($owner, $companyId, $journalId, $currency, $amountMinor, $reason): array {
            $this->authorize($owner, $companyId, true);
            $this->postingEnabled();
            $this->lockCompany($companyId);
            $existing = DB::table('finance_journals')->where('id', $journalId)->first();
            if ($existing) {
                if ($existing->company_id === $companyId
                    && $existing->entry_type === 'opening_cash'
                    && (int) $existing->created_by === (int) $owner->getKey()
                    && (int) $existing->amount_minor === $amountMinor
                    && $existing->currency_code === $currency
                    && $existing->reason === $reason) {
                    return ['id' => $journalId, 'type' => 'opening_cash', 'amountMinor' => $amountMinor];
                }
                throw new ConflictHttpException('Opening journal ID has a different payload.');
            }
            $this->assertDayOpen($companyId, $currency);
            $active = DB::table('finance_journals as opening')
                ->where('opening.company_id', $companyId)
                ->where('opening.entry_type', 'opening_cash')
                ->where('opening.currency_code', $currency)
                ->whereNotExists(function ($q): void {
                    $q->selectRaw('1')->from('finance_journals as reversal')
                        ->whereColumn('reversal.reversal_of', 'opening.id');
                })->exists();
            if ($active) {
                throw new ConflictHttpException('A cash opening already exists; use audited reversal to correct it.');
            }
            $this->journal($owner, $companyId, $journalId, 'opening_cash',
                $currency, $amountMinor, null, $reason, [
                    ['cash_on_hand', 'debit', $amountMinor],
                    ['opening_equity', 'credit', $amountMinor],
                ]);
            return ['id' => $journalId, 'type' => 'opening_cash', 'amountMinor' => $amountMinor];
        }, 3);
    }

    public function reverseOpening(
        User $owner, string $companyId, string $journalId, string $originalId, string $reason,
    ): array {
        $this->authorize($owner, $companyId, true);
        $this->postingEnabled();
        $reason = $this->reason($reason);
        if (! Str::isUuid($journalId) || ! Str::isUuid($originalId) || $journalId === $originalId) {
            throw new InvalidArgumentException('Original and reversal IDs must be distinct UUIDs.');
        }
        return DB::transaction(function () use ($owner, $companyId, $journalId, $originalId, $reason): array {
            $this->authorize($owner, $companyId, true);
            $this->postingEnabled();
            $this->lockCompany($companyId);
            $existing = DB::table('finance_journals')->where('id', $journalId)->first();
            if ($existing) {
                if ($existing->company_id === $companyId
                    && $existing->entry_type === 'reversal'
                    && $existing->reversal_of === $originalId
                    && (int) $existing->created_by === (int) $owner->getKey()
                    && $existing->reason === $reason) {
                    return ['id' => $journalId, 'type' => 'reversal',
                        'amountMinor' => (int) $existing->amount_minor];
                }
                throw new ConflictHttpException('Reversal journal ID has a different payload.');
            }
            $original = DB::table('finance_journals')->where('company_id', $companyId)
                ->where('id', $originalId)->first();
            if (! $original || $original->entry_type !== 'opening_cash') {
                throw new AuthorizationException('Only an existing company opening can be reversed.');
            }
            $this->assertDayOpen($companyId, $original->currency_code);
            if (DB::table('finance_journals')->where('reversal_of', $originalId)->exists()) {
                throw new ConflictHttpException('Opening was already reversed.');
            }
            $originalLines = DB::table('finance_journal_lines')
                ->where('company_id', $companyId)->where('journal_id', $originalId)->get();
            if ($originalLines->count() !== 2) {
                throw new ConflictHttpException('Original journal is incomplete.');
            }
            $lines = $originalLines->map(fn ($row) => [
                $row->account_code, $row->side === 'debit' ? 'credit' : 'debit',
                (int) $row->amount_minor,
            ])->all();
            $this->journal($owner, $companyId, $journalId, 'reversal',
                $original->currency_code, (int) $original->amount_minor,
                $originalId, $reason, $lines);
            return ['id' => $journalId, 'type' => 'reversal', 'amountMinor' => (int) $original->amount_minor];
        }, 3);
    }

    /**
     * Read-only cumulative physical cash expectation, never reconciles funds
     * by silently altering journal history.
     */
    public function balance(User $actor, string $companyId, string $currency, string $asOfDate): int
    {
        $this->authorize($actor, $companyId);
        $this->currency($currency);
        $date = $this->date($asOfDate);
        return $this->cashBalance($companyId, $currency, $date);
    }

    public function closeCash(
        User $owner, string $companyId, string $closeId, string $currency,
        string $closeDate, int $countedMinor, string $reason,
    ): array {
        $this->authorize($owner, $companyId, true);
        $this->postingEnabled();
        $this->currency($currency);
        $date = $this->date($closeDate);
        $reason = $this->reason($reason);
        if (! Str::isUuid($closeId) || $countedMinor < 0 || $countedMinor > 100000000000
            || $date > now('UTC')->toDateString()) {
            throw new InvalidArgumentException('Invalid cash count, closeout ID or future date.');
        }
        return DB::transaction(function () use (
            $owner, $companyId, $closeId, $currency, $date, $countedMinor, $reason
        ): array {
            $this->authorize($owner, $companyId, true);
            $this->postingEnabled();
            $this->lockCompany($companyId);
            $existing = DB::table('finance_cash_closeouts')->where('id', $closeId)->first();
            if ($existing) {
                if ($existing->company_id === $companyId
                    && $existing->currency_code === $currency && $existing->close_date === $date
                    && (int) $existing->closed_by === (int) $owner->getKey()
                    && (int) $existing->counted_minor === $countedMinor && $existing->reason === $reason) {
                    return $this->closeSummary($existing);
                }
                throw new ConflictHttpException('Closeout request ID payload changed.');
            }
            if (DB::table('finance_cash_closeouts')->where('company_id', $companyId)
                ->where('currency_code', $currency)->where('close_date', $date)->exists()) {
                throw new ConflictHttpException('This currency and UTC day are already closed.');
            }
            $expected = $this->cashBalance($companyId, $currency, $date);
            $variance = $countedMinor - $expected;
            DB::table('finance_cash_closeouts')->insert([
                'id' => $closeId, 'company_id' => $companyId, 'close_date' => $date,
                'currency_code' => $currency, 'expected_minor' => $expected,
                'counted_minor' => $countedMinor, 'variance_minor' => $variance,
                'closed_by' => $owner->getKey(), 'reason' => $reason,
            ]);
            $this->audit($owner, $companyId, 'finance.cash_closed', 'finance_cash_closeout',
                $closeId, ['closeDate' => $date, 'currency' => $currency,
                    'expectedMinor' => $expected, 'countedMinor' => $countedMinor,
                    'varianceMinor' => $variance], $reason);
            return ['id' => $closeId, 'currency' => $currency, 'date' => $date,
                'expectedMinor' => $expected, 'countedMinor' => $countedMinor,
                'varianceMinor' => $variance];
        }, 3);
    }

    private function closeSummary(object $row): array
    {
        return ['id' => $row->id, 'currency' => $row->currency_code, 'date' => $row->close_date,
            'expectedMinor' => (int) $row->expected_minor,
            'countedMinor' => (int) $row->counted_minor, 'varianceMinor' => (int) $row->variance_minor];
    }

    private function cashBalance(string $companyId, string $currency, string $date): int
    {
        return (int) DB::table('finance_journal_lines as line')
            ->join('finance_journals as journal', function ($join): void {
                $join->on('journal.id', '=', 'line.journal_id')
                    ->on('journal.company_id', '=', 'line.company_id');
            })
            ->where('journal.company_id', $companyId)
            ->where('journal.currency_code', $currency)
            ->whereDate('journal.created_at', '<=', $date)
            ->where('line.account_code', 'cash_on_hand')
            ->selectRaw("COALESCE(SUM(CASE WHEN line.side = 'debit' THEN line.amount_minor ELSE -line.amount_minor END), 0) AS balance")
            ->value('balance');
    }

    private function date(string $date): string
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Cash dates must use a valid YYYY-MM-DD UTC date.');
        }
        return $date;
    }

    private function lockCompany(string $companyId): void
    {
        if (! DB::table('companies')->where('id', $companyId)
            ->where('is_active', true)->lockForUpdate()->first(['id'])) {
            throw new AuthorizationException('Inactive or inaccessible company.');
        }
    }

    private function assertDayOpen(string $companyId, string $currency): void
    {
        if (DB::table('finance_cash_closeouts')->where('company_id', $companyId)
            ->where('currency_code', $currency)
            ->where('close_date', now('UTC')->toDateString())->exists()) {
            throw new ConflictHttpException('This UTC cash day is closed; no more postings are allowed.');
        }
    }

    private function journal(
        User $owner, string $companyId, string $id, string $type,
        string $currency, int $amount, ?string $reversalOf,
        string $reason, array $lines,
    ): void {
        DB::table('finance_journals')->insert([
            'id' => $id, 'company_id' => $companyId,
            'entry_type' => $type, 'currency_code' => $currency,
            'amount_minor' => $amount, 'reversal_of' => $reversalOf,
            'created_by' => $owner->getKey(), 'reason' => $reason,
        ]);
        foreach ($lines as [$account, $side, $minor]) {
            DB::table('finance_journal_lines')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'journal_id' => $id, 'account_code' => $account,
                'side' => $side, 'amount_minor' => $minor,
            ]);
        }
        // Validate deferrable SQL balance now, not only at an outer PHPUnit
        // transaction's COMMIT. Reset for another journal in same transaction.
        DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced IMMEDIATE');
        DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced DEFERRED');
        $this->audit($owner, $companyId, 'finance.'.$type.'_posted', 'finance_journal',
            $id, ['type' => $type, 'amountMinor' => $amount,
                'currency' => $currency, 'reversalOf' => $reversalOf], $reason);
    }

    private function audit(
        User $actor, string $companyId, string $action, string $entityType,
        string $entityId, array $after, string $reason,
    ): void {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(), 'action' => $action,
            'entity_type' => $entityType, 'entity_id' => $entityId,
            'before_values' => null,
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }
}
