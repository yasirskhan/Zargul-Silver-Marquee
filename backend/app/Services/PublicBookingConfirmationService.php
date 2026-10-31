<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Atomically converts a verified, active hold into a confirmed public booking. */
final class PublicBookingConfirmationService
{
    public function confirm(string $companyId, string $holdToken): array
    {
        if (! Str::isUuid($companyId) || preg_match('/^[a-f0-9]{64}$/D', $holdToken) !== 1) {
            throw new InvalidArgumentException('Invalid confirmation token.');
        }
        $hash = hash('sha256', $holdToken);

        return DB::transaction(function () use ($companyId, $hash, $holdToken): array {
            $hold = DB::table('public_booking_holds')->where('company_id', $companyId)
                ->where('token_hash', $hash)->lockForUpdate()->first();
            if (! $hold) {
                throw new ConflictHttpException('This hold is no longer active.');
            }
            if ($hold->expires_at <= now()) {
                throw new ConflictHttpException('This hold expired before payment confirmation.');
            }
            DB::table('halls')->where('company_id', $companyId)
                ->where('id', $hold->hall_id)->lockForUpdate()->first();
            $evidence = DB::table('public_booking_payment_evidence')
                ->where('company_id', $companyId)->where('hold_id', $hold->id)->first();
            if (! $evidence || (int) $evidence->amount_minor !== (int) $hold->deposit_minor
                || $evidence->currency_code !== $hold->currency_code) {
                throw new ConflictHttpException('Independently verified deposit evidence is required.');
            }
            $quote = json_decode($hold->quote, true, 64, JSON_THROW_ON_ERROR);
            $hallQuote = $quote['halls'][0] ?? null;
            $hall = DB::table('halls')->where('company_id', $companyId)
                ->where('id', $hold->hall_id)->first();
            if (! $hallQuote || ! $hall || $hallQuote['hallId'] !== $hold->hall_id
                || (int) $hall->guest_pricing_revision !== (int) $hallQuote['guestPricingRevision']
                || (int) $hall->program_rules_revision !== (int) $hallQuote['programPricingRevision']
                || (int) $hall->addon_catalog_revision !== (int) $hallQuote['addonCatalogRevision']
                || (int) $hall->capacity < (int) $hold->guests || ! $hall->is_bookable) {
                throw new ConflictHttpException('Pricing or hall eligibility changed; reconfirm required.');
            }
            $occupied = DB::table('hall_slot_claims')->where('company_id', $companyId)
                ->where('hall_id', $hold->hall_id)->where('event_date', $hold->event_date)
                ->where('program', $hold->program)->where(fn ($q) => $q->where('status', 'confirmed')
                    ->orWhere(fn ($q) => $q->where('status', 'hold')->where('expires_at', '>', now())))->exists()
                || DB::table('venue_reservations')->where('company_id', $companyId)
                    ->where('hall_id', $hold->hall_id)->where('event_date', $hold->event_date)
                    ->where('program', $hold->program)->where('status', 'confirmed')->exists()
                || DB::table('public_booking_confirmations')->where('company_id', $companyId)
                    ->where('hall_id', $hold->hall_id)->where('event_date', $hold->event_date)
                    ->where('program', $hold->program)->exists();
            if ($occupied) {
                throw new ConflictHttpException('The slot is no longer exclusively owned by this hold.');
            }
            $id = (string) Str::uuid();
            DB::table('public_booking_confirmations')->insert([
                'id' => $id, 'company_id' => $companyId, 'hall_id' => $hold->hall_id,
                'event_date' => $hold->event_date, 'program' => $hold->program,
                'guests' => $hold->guests, 'quote' => $hold->quote,
                'currency_code' => $hold->currency_code, 'total_minor' => $hold->total_minor,
                'deposit_minor' => $hold->deposit_minor,
                'customer_name' => $hold->customer_name,
                'customer_email' => $hold->customer_email,
                'customer_phone' => $hold->customer_phone,
                'hold_id' => $hold->id, 'payment_evidence_id' => $evidence->id,
                'token_hash' => $hash,
                'token_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString($holdToken),
                'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('public_booking_confirmation_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'confirmation_id' => $id, 'action' => 'confirmed',
                'snapshot' => json_encode(['depositMinor' => (int) $hold->deposit_minor,
                    'currencyCode' => $hold->currency_code,
                    'paymentEvidenceId' => $evidence->id], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);
            DB::table('public_booking_hold_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'hold_id' => $hold->id, 'action' => 'confirmed',
                'snapshot' => json_encode(['confirmationId' => $id,
                    'depositMinor' => (int) $hold->deposit_minor], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);
            DB::table('public_booking_holds')->where('id', $hold->id)->delete();
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => null, 'action' => 'public_booking.confirmed',
                'entity_type' => 'public_booking_confirmation', 'entity_id' => $id,
                'before_values' => null,
                'after_values' => json_encode(['hallId' => $hold->hall_id,
                    'date' => $hold->event_date, 'program' => $hold->program,
                    'depositMinor' => (int) $hold->deposit_minor], JSON_THROW_ON_ERROR),
                'context' => json_encode(['source' => 'synthetic_verified_payment'], JSON_THROW_ON_ERROR),
            ]);

            return ['confirmationId' => $id, 'token' => $holdToken,
                'confirmedAt' => now()->toIso8601String(),
                'depositMinor' => (int) $hold->deposit_minor];
        }, 3);
    }
}