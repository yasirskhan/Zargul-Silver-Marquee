<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Operator-gated numbered INTERNAL quote issuance. Nothing is sent to a
 * customer; this is not a tax invoice, payment instruction or receipt.
 */
final class VenueQuoteIssueService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function authorize(User $actor, string $companyId, string $hallId): void
    {
        $hall = Hall::query()->where('company_id', $companyId)
            ->where('id', $hallId)->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $this->access->canManageCustomerProfiles($actor, $companyId)
            || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('Current owner/admin permission for internal quote required.');
        }
    }

    public function issue(User $actor, string $companyId, string $snapshotId, string $issueId, string $purpose): array
    {
        if (config('quote_controls.issuance_enabled') !== true) {
            throw new ConflictHttpException('Numbered quote issuance requires operator release approval.');
        }
        if (! Str::isUuid($snapshotId) || ! Str::isUuid($issueId)) {
            throw new InvalidArgumentException('Valid snapshot and issue IDs required.');
        }
        $purpose = trim($purpose);
        if (mb_strlen($purpose) < 3 || mb_strlen($purpose) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $purpose)) {
            throw new InvalidArgumentException('Safe plain-text issue purpose required.');
        }
        return DB::transaction(function () use ($actor, $companyId, $snapshotId, $issueId, $purpose): array {
            if (config('quote_controls.issuance_enabled') !== true) {
                throw new ConflictHttpException('Quote issue release gate is closed.');
            }
            // Serializing on the company row prevents duplicate numbering from
            // concurrent distinct issue requests, in addition to unique indexes.
            abort_unless(DB::table('companies')->where('id', $companyId)->lockForUpdate()->exists(), 403);
            $snapshot = DB::table('venue_quote_snapshots')
                ->where('company_id', $companyId)->where('id', $snapshotId)->first();
            if (! $snapshot) {
                throw new AuthorizationException('This frozen quote is inaccessible.');
            }
            $this->authorize($actor, $companyId, $snapshot->hall_id);
            $byId = DB::table('venue_quote_issues')->where('id', $issueId)->first();
            $bySnapshot = DB::table('venue_quote_issues')
                ->where('company_id', $companyId)->where('snapshot_id', $snapshotId)->first();
            if ($byId || $bySnapshot) {
                if ($byId && $bySnapshot && $byId->id === $bySnapshot->id
                    && $byId->company_id === $companyId
                    && $byId->snapshot_id === $snapshotId
                    && (int) $byId->issued_by === (int) $actor->getKey()
                    && $byId->purpose === $purpose) {
                    return $this->summary($byId);
                }
                throw new ConflictHttpException('This quote issue or snapshot was already used.');
            }
            $reservation = DB::table('venue_reservations')->where('company_id', $companyId)
                ->where('id', $snapshot->reservation_id)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'confirmed'
                || $reservation->hall_id !== $snapshot->hall_id
                || (int) $reservation->revision !== (int) $snapshot->reservation_revision) {
                throw new ConflictHttpException('Reservation changed since internal quote freeze.');
            }
            $number = 1 + (int) DB::table('venue_quote_issues')
                ->where('company_id', $companyId)->max('number');
            if ($number > 999999999) {
                throw new ConflictHttpException('Numbered quote sequence exhausted.');
            }
            $reference = 'Q-'.str_pad((string) $number, 9, '0', STR_PAD_LEFT);
            DB::table('venue_quote_issues')->insert([
                'id' => $issueId, 'company_id' => $companyId,
                'snapshot_id' => $snapshotId, 'reservation_id' => $reservation->id,
                'number' => $number, 'document_number' => $reference,
                'currency_code' => $snapshot->currency_code,
                'subtotal_minor' => $snapshot->subtotal_minor,
                'frozen_lines' => $snapshot->frozen_lines,
                'purpose' => $purpose, 'issued_by' => $actor->getKey(),
                'issued_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'venue_quote.internal_issued',
                'entity_type' => 'venue_quote_issue', 'entity_id' => $issueId,
                'before_values' => null,
                'after_values' => json_encode([
                    'snapshotId' => $snapshotId, 'reservationId' => $reservation->id,
                    'documentNumber' => $reference, 'currency' => $snapshot->currency_code,
                    'subtotalMinor' => $snapshot->subtotal_minor,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['purpose' => $purpose], JSON_THROW_ON_ERROR),
            ]);
            return $this->summary(DB::table('venue_quote_issues')->where('id', $issueId)->first());
        }, 3);
    }

    public function list(User $actor, string $companyId, string $reservationId): array
    {
        if (! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('Valid reservation required.');
        }
        $reservation = DB::table('venue_reservations')->where('company_id', $companyId)
            ->where('id', $reservationId)->first();
        if (! $reservation) {
            throw new AuthorizationException('Reservation unavailable.');
        }
        $this->authorize($actor, $companyId, $reservation->hall_id);
        return DB::table('venue_quote_issues')->where('company_id', $companyId)
            ->where('reservation_id', $reservationId)
            ->orderByDesc('number')->limit(50)->get()
            ->map(fn ($row) => $this->summary($row))->all();
    }

    private function summary(object $row): array
    {
        return [
            'id' => $row->id, 'number' => $row->document_number,
            'snapshotId' => $row->snapshot_id, 'reservationId' => $row->reservation_id,
            'currency' => $row->currency_code, 'subtotalMinor' => (int) $row->subtotal_minor,
            'lines' => json_decode($row->frozen_lines, true, 512, JSON_THROW_ON_ERROR),
            'status' => $row->status, 'issuedAt' => (string) $row->issued_at,
        ];
    }
}
