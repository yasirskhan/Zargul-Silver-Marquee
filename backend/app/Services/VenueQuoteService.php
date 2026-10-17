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
 * Phase B: reproducible NONBINDING pricing snapshots on real internal
 * reservations. Invoices, taxes, discounts, collections and refunds require
 * separate operator-approved issuance and ledger controls.
 */
final class VenueQuoteService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function authorized(User $actor, string $companyId, string $hallId): Hall
    {
        $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $this->access->canManageCustomerProfiles($actor, $companyId)
            || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('Current company owner or admin required for private pricing.');
        }
        return $hall;
    }

    public function create(
        User $actor, string $companyId, string $reservationId, string $quoteId,
        int $expectedRevision, int $extraChairs, int $extraTables,
        array $addons, string $reason,
    ): array {
        if (! Str::isUuid($reservationId) || ! Str::isUuid($quoteId)
            || $expectedRevision < 1 || $extraChairs < 0 || $extraChairs > 100000
            || $extraTables < 0 || $extraTables > 100000 || ! array_is_list($addons)
            || count($addons) > 100) {
            throw new InvalidArgumentException('Invalid quote reference, revision or seating quantities.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $reason)) {
            throw new InvalidArgumentException('A safe internal quote reason is required.');
        }
        $quantities = [];
        foreach ($addons as $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['code', 'quantity']
                || ! is_string($entry['code'])
                || ! preg_match('/\A[a-z][a-z0-9_-]{1,39}\z/', $entry['code'])
                || ! is_int($entry['quantity']) || $entry['quantity'] < 1
                || $entry['quantity'] > 100000 || isset($quantities[$entry['code']])) {
                throw new InvalidArgumentException('Use unique approved add-on codes and bounded quantities.');
            }
            $quantities[$entry['code']] = $entry['quantity'];
        }
        $fingerprint = hash('sha256', json_encode([
            $reservationId, $expectedRevision, $extraChairs, $extraTables,
            $quantities, $reason,
        ], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use (
            $actor, $companyId, $reservationId, $quoteId, $expectedRevision,
            $extraChairs, $extraTables, $quantities, $reason, $fingerprint
        ): array {
            $reservation = DB::table('venue_reservations')
                ->where('company_id', $companyId)->where('id', $reservationId)
                ->lockForUpdate()->first();
            if (! $reservation) {
                throw new AuthorizationException('Reservation not found for this company.');
            }
            $hall = $this->authorized($actor, $companyId, $reservation->hall_id);
            $existing = DB::table('venue_quote_snapshots')->where('id', $quoteId)->first();
            if ($existing) {
                if ($existing->company_id === $companyId
                    && $existing->reservation_id === $reservationId
                    && $existing->created_by == $actor->getKey()
                    && (int) $existing->reservation_revision === $expectedRevision
                    && $existing->reason === $reason
                    && hash_equals($existing->request_fingerprint, $fingerprint)) {
                    return $this->summary($existing);
                }
                throw new ConflictHttpException('Quote idempotency key already belongs to another request.');
            }
            if ($reservation->status !== 'confirmed'
                || (int) $reservation->revision !== $expectedRevision
                || (int) $reservation->guests > (int) $hall->capacity) {
                throw new ConflictHttpException('Confirmed reservation revision or hall capacity changed.');
            }
            $programRev = (int) $hall->program_rules_revision;
            $seatingRev = (int) $hall->seating_price_revision;
            $addonRev = (int) $hall->addon_catalog_revision;
            if ($programRev < 1 || $seatingRev < 1) {
                throw new ConflictHttpException('Approved hall rental and seating revisions are required.');
            }
            $program = DB::table('hall_program_rule_revisions')->where('company_id', $companyId)
                ->where('hall_id', $hall->id)->where('revision_number', $programRev)->first();
            $seat = DB::table('hall_seating_price_revisions')->where('company_id', $companyId)
                ->where('hall_id', $hall->id)->where('revision_number', $seatingRev)->first();
            $catalog = $addonRev > 0
                ? DB::table('hall_addon_catalog_revisions')->where('company_id', $companyId)
                    ->where('hall_id', $hall->id)->where('revision_number', $addonRev)->first()
                : null;
            if (! $program || ! $seat || ($addonRev > 0 && ! $catalog)
                || ($catalog && $catalog->currency_code !== $program->currency_code)
                || $seat->currency_code !== $program->currency_code
                || (int) $seat->capacity !== (int) $hall->capacity
                || $extraChairs + (int) $seat->included_chairs > (int) $hall->capacity) {
                throw new ConflictHttpException('Hall price revisions, currency or seating capacity require review.');
            }
            $rules = json_decode($program->rules, true, 512, JSON_THROW_ON_ERROR);
            $rate = $rules[$reservation->program]['rentalRateMinor'] ?? null;
            if (! is_int($rate) || $rate < 0) {
                throw new ConflictHttpException('Approved rental price for the selected program is missing.');
            }
            $lines = [[
                'code' => 'hall_rental', 'quantity' => 1,
                'rateMinor' => $rate, 'amountMinor' => $rate,
            ]];
            foreach ([
                ['extra_chairs', $extraChairs, (int) $seat->extra_chair_rate_minor],
                ['extra_tables', $extraTables, (int) $seat->extra_table_rate_minor],
            ] as [$code, $qty, $unit]) {
                if ($qty > 0) {
                    $lines[] = ['code' => $code, 'quantity' => $qty,
                        'rateMinor' => $unit, 'amountMinor' => $qty * $unit];
                }
            }
            $items = $catalog ? json_decode($catalog->items, true, 512, JSON_THROW_ON_ERROR) : [];
            $approved = [];
            foreach ($items as $item) {
                if ($item['enabled'] === true) {
                    $approved[$item['code']] = $item;
                }
            }
            foreach ($quantities as $code => $quantity) {
                if (! isset($approved[$code])) {
                    throw new ConflictHttpException('Requested add-on is not currently approved for this hall.');
                }
                $item = $approved[$code];
                $qty = $item['chargeUnit'] === 'flat' ? 1
                    : ($item['chargeUnit'] === 'per_guest'
                        ? (int) $reservation->guests : $quantity);
                if ($item['chargeUnit'] === 'flat' && $quantity !== 1) {
                    throw new InvalidArgumentException('Flat add-ons must use one unit.');
                }
                $unit = $item['rateMinor'];
                $lines[] = ['code' => $code, 'name' => $item['name'],
                    'chargeUnit' => $item['chargeUnit'], 'quantity' => $qty,
                    'rateMinor' => $unit, 'amountMinor' => $qty * $unit];
            }
            $total = 0;
            foreach ($lines as $line) {
                if ($line['amountMinor'] < 0 || $line['amountMinor'] > 100000000000
                    || $total > 100000000000 - $line['amountMinor']) {
                    throw new InvalidArgumentException('Quoted amount exceeds the approved safety limit.');
                }
                $total += $line['amountMinor'];
            }
            $payload = [
                'id' => $quoteId, 'company_id' => $companyId,
                'reservation_id' => $reservationId,
                'reservation_revision' => $expectedRevision, 'hall_id' => $hall->id,
                'program_revision' => $programRev, 'seating_revision' => $seatingRev,
                'addon_revision' => $addonRev, 'currency_code' => $program->currency_code,
                'frozen_lines' => json_encode($lines, JSON_THROW_ON_ERROR),
                'request_fingerprint' => $fingerprint,
                'subtotal_minor' => $total, 'created_by' => $actor->getKey(),
                'reason' => $reason,
            ];
            DB::table('venue_quote_snapshots')->insert($payload);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'venue_quote.snapshot_created',
                'entity_type' => 'venue_quote_snapshot', 'entity_id' => $quoteId,
                'before_values' => null,
                'after_values' => json_encode([
                    'reservationId' => $reservationId, 'reservationRevision' => $expectedRevision,
                    'programRevision' => $programRev, 'seatingRevision' => $seatingRev,
                    'addonRevision' => $addonRev, 'currencyCode' => $program->currency_code,
                    'subtotalMinor' => $total,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
            return $this->summary(DB::table('venue_quote_snapshots')->where('id', $quoteId)->first());
        }, 3);
    }

    public function list(User $actor, string $companyId, string $reservationId): array
    {
        if (! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('Invalid reservation identifier.');
        }
        $reservation = DB::table('venue_reservations')
            ->where('company_id', $companyId)->where('id', $reservationId)->first();
        if (! $reservation) {
            throw new AuthorizationException('Reservation unavailable.');
        }
        $this->authorized($actor, $companyId, $reservation->hall_id);
        return DB::table('venue_quote_snapshots')
            ->where('company_id', $companyId)->where('reservation_id', $reservationId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()
            ->map(fn ($row) => $this->summary($row))->all();
    }

    private function summary(object $row): array
    {
        return [
            'id' => $row->id, 'reservationId' => $row->reservation_id,
            'reservationRevision' => (int) $row->reservation_revision,
            'programRevision' => (int) $row->program_revision,
            'seatingRevision' => (int) $row->seating_revision,
            'addonRevision' => (int) $row->addon_revision,
            'currency' => $row->currency_code,
            'lines' => json_decode($row->frozen_lines, true, 512, JSON_THROW_ON_ERROR),
            'subtotalMinor' => (int) $row->subtotal_minor, 'status' => $row->status,
        ];
    }
}
