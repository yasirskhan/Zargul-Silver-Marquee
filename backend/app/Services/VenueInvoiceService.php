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
 * Controlled invoice issuance from a previously numbered immutable internal
 * quote. Never posts a payment, refunds or alters the quote itself.
 */
final class VenueInvoiceService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function authorized(User $actor, string $companyId, string $hallId): void
    {
        $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $this->access->canManageCustomerProfiles($actor, $companyId)
            || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('Current owner/admin invoice authority required.');
        }
    }

    private function gate(): int
    {
        if (config('invoice_controls.issuance_enabled') !== true) {
            throw new ConflictHttpException('Invoice issuance is disabled until operator approval.');
        }
        $tax = config('invoice_controls.tax_rate_bps');
        if (! is_int($tax) || $tax < 0 || $tax > 10000) {
            throw new ConflictHttpException('Approved venue tax treatment is not configured.');
        }
        return $tax;
    }

    public function issue(User $actor, string $companyId, string $quoteIssueId, string $invoiceId, string $reason): array
    {
        $this->gate();
        if (! Str::isUuid($quoteIssueId) || ! Str::isUuid($invoiceId)) {
            throw new InvalidArgumentException('Valid numbered quote and invoice IDs are required.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $reason)) {
            throw new InvalidArgumentException('Safe plain-text invoice reason required.');
        }
        return DB::transaction(function () use ($actor, $companyId, $quoteIssueId, $invoiceId, $reason): array {
            $taxBps = $this->gate();
            $company = DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            if (! $company) {
                throw new AuthorizationException('Company not accessible.');
            }
            $quote = DB::table('venue_quote_issues')
                ->where('company_id', $companyId)->where('id', $quoteIssueId)->first();
            if (! $quote) {
                throw new AuthorizationException('Numbered quote unavailable in this company.');
            }
            $reservation = DB::table('venue_reservations')
                ->where('company_id', $companyId)->where('id', $quote->reservation_id)
                ->lockForUpdate()->first();
            if (! $reservation) {
                throw new AuthorizationException('Linked reservation unavailable.');
            }
            $this->authorized($actor, $companyId, $reservation->hall_id);
            $idExisting = DB::table('venue_invoices')->where('id', $invoiceId)->first();
            $sourceExisting = DB::table('venue_invoices')->where('company_id', $companyId)
                ->where('quote_issue_id', $quoteIssueId)->first();
            if ($idExisting || $sourceExisting) {
                if ($idExisting && $sourceExisting && $idExisting->id === $sourceExisting->id
                    && $idExisting->company_id === $companyId
                    && (int) $idExisting->issued_by === (int) $actor->getKey()
                    && $idExisting->reason === $reason) {
                    return $this->summary($idExisting);
                }
                throw new ConflictHttpException('Invoice key or source quote has already been issued.');
            }
            $source = DB::table('venue_quote_snapshots')->where('company_id', $companyId)
                ->where('id', $quote->snapshot_id)->first();
            if (! $source || $source->reservation_id !== $reservation->id
                || (int) $source->reservation_revision !== (int) $reservation->revision
                || $reservation->status !== 'confirmed' || $quote->status !== 'internal_issued') {
                throw new ConflictHttpException('Reservation changed or quote source is not valid for invoice.');
            }
            $subtotal = (int) $quote->subtotal_minor;
            $taxMinor = intdiv($subtotal * $taxBps + 5000, 10000);
            $total = $subtotal + $taxMinor;
            if ($total > 100000000000) {
                throw new ConflictHttpException('Invoice amount exceeds approved limits.');
            }
            $number = 1 + (int) DB::table('venue_invoices')
                ->where('company_id', $companyId)->max('number');
            if ($number > 999999999) {
                throw new ConflictHttpException('Invoice number sequence exhausted.');
            }
            $reference = 'INV-'.str_pad((string) $number, 9, '0', STR_PAD_LEFT);
            DB::table('venue_invoices')->insert([
                'id' => $invoiceId, 'company_id' => $companyId,
                'quote_issue_id' => $quoteIssueId,
                'reservation_id' => $reservation->id, 'customer_id' => $reservation->customer_id,
                'number' => $number, 'invoice_number' => $reference,
                'tax_rate_bps' => $taxBps, 'currency_code' => $quote->currency_code,
                'frozen_lines' => $quote->frozen_lines,
                'subtotal_minor' => $subtotal, 'tax_minor' => $taxMinor,
                'total_minor' => $total, 'reason' => $reason,
                'issued_by' => $actor->getKey(), 'issued_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'venue_invoice.issued',
                'entity_type' => 'venue_invoice', 'entity_id' => $invoiceId,
                'before_values' => null,
                'after_values' => json_encode([
                    'invoiceNumber' => $reference, 'quoteIssueId' => $quoteIssueId,
                    'reservationId' => $reservation->id, 'currency' => $quote->currency_code,
                    'subtotalMinor' => $subtotal, 'taxBps' => $taxBps,
                    'taxMinor' => $taxMinor, 'totalMinor' => $total,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
            return $this->summary(DB::table('venue_invoices')->where('id', $invoiceId)->first());
        }, 3);
    }

    public function list(User $actor, string $companyId, string $reservationId): array
    {
        if (! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('Valid reservation required.');
        }
        $reservation = DB::table('venue_reservations')
            ->where('company_id', $companyId)->where('id', $reservationId)->first();
        if (! $reservation) {
            throw new AuthorizationException('Reservation unavailable.');
        }
        $this->authorized($actor, $companyId, $reservation->hall_id);
        return DB::table('venue_invoices')->where('company_id', $companyId)
            ->where('reservation_id', $reservationId)
            ->orderByDesc('number')->limit(50)->get()
            ->map(fn ($row) => $this->summary($row))->all();
    }

    private function summary(object $row): array
    {
        return [
            'id' => $row->id, 'invoiceNumber' => $row->invoice_number,
            'quoteIssueId' => $row->quote_issue_id, 'reservationId' => $row->reservation_id,
            'currency' => $row->currency_code, 'subtotalMinor' => (int) $row->subtotal_minor,
            'taxRateBps' => (int) $row->tax_rate_bps, 'taxMinor' => (int) $row->tax_minor,
            'totalMinor' => (int) $row->total_minor, 'status' => $row->status,
            'lines' => json_decode($row->frozen_lines, true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
