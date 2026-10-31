<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Release-gated anonymous customer hold boundary; never uses staff authorization. */
final class PublicBookingHoldService
{
    public function __construct(private readonly PublicBookingQuoteService $quotes) {}

    /** @return array{holdId:string,token:string,expiresAt:string,depositMinor:int} */
    public function create(string $companyId, array $quote, string $hallId,
        array $customer, string $idempotencyKey): array
    {
        if (config('public_booking.enabled') !== true || config('public_booking.holds_enabled') !== true) {
            throw new ConflictHttpException('Public booking holds are not enabled.');
        }
        if (! Str::isUuid($idempotencyKey) || ! Str::isUuid($companyId)) {
            throw new InvalidArgumentException('A valid hold request key is required.');
        }
        $name = trim((string) ($customer['name'] ?? ''));
        $email = trim((string) ($customer['email'] ?? ''));
        $phone = trim((string) ($customer['phone'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120
            || preg_match('/[<>\x00-\x1F\x7F]/u', $name)
            || ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254))
            || ($phone !== '' && (! preg_match('/^\+?[0-9][0-9 .-]{5,30}$/D', $phone) || strlen($phone) > 32))
            || ($email === '' && $phone === '')) {
            throw new InvalidArgumentException('Provide a valid name and at least one contact method.');
        }
        $existing = DB::table('public_booking_holds')->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->hall_id !== $hallId || $existing->quote_hash !== $quote['quoteHash']
                || $existing->customer_name !== $name
                || (string) $existing->customer_email !== $email
                || (string) $existing->customer_phone !== $phone) {
                throw new ConflictHttpException('This request key was already used for another hold.');
            }
            try {
                $original = \Illuminate\Support\Facades\Crypt::decryptString($existing->token_ciphertext);
            } catch (\Throwable) {
                throw new ConflictHttpException('This hold request cannot be replayed.');
            }
            return ['holdId' => $existing->id, 'token' => $original,
                'expiresAt' => \Illuminate\Support\Carbon::parse($existing->expires_at)->toIso8601String(),
                'depositMinor' => (int) $existing->deposit_minor];
        }
        $review = $this->quotes->revalidate($quote, $hallId);
        $hall = $review['hall'];
        $deposit = intdiv($hall['totalMinor'] * 5 + 99, 100);
        $minutes = max(5, min(60, (int) config('public_booking.hold_minutes', 15)));
        $token = bin2hex(random_bytes(32));

        return DB::transaction(function () use ($companyId, $hallId, $quote, $hall,
            $customer, $idempotencyKey, $name, $email, $phone, $deposit, $minutes, $token): array {
            $locked = DB::table('halls')->where('company_id', $companyId)
                ->where('id', $hallId)->lockForUpdate()->exists();
            if (! $locked) {
                throw new ConflictHttpException('The selected hall is unavailable.');
            }
            $existing = DB::table('public_booking_holds')->where('company_id', $companyId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->hall_id !== $hallId || $existing->quote_hash !== $quote['quoteHash']) {
                    throw new ConflictHttpException('This request key was already used for another hold.');
                }
                try {
                    $original = \Illuminate\Support\Facades\Crypt::decryptString($existing->token_ciphertext);
                } catch (\Throwable) {
                    throw new ConflictHttpException('This hold request cannot be replayed.');
                }
                return ['holdId' => $existing->id, 'token' => $original,
                    'expiresAt' => \Illuminate\Support\Carbon::parse($existing->expires_at)->toIso8601String(),
                    'depositMinor' => (int) $existing->deposit_minor];
            }
            $this->expire($companyId);
            $occupied = DB::table('hall_slot_claims')->where('company_id', $companyId)
                ->where('hall_id', $hallId)->where('event_date', $hall['date'])
                ->where('program', $hall['program'])
                ->where(fn ($q) => $q->where('status', 'confirmed')
                    ->orWhere(fn ($q) => $q->where('status', 'hold')
                        ->where('expires_at', '>', now())))->exists()
                || DB::table('venue_reservations')->where('company_id', $companyId)
                    ->where('hall_id', $hallId)->where('event_date', $hall['date'])
                    ->where('program', $hall['program'])->where('status', 'confirmed')->exists()
                || DB::table('public_booking_holds')->where('company_id', $companyId)
                    ->where('hall_id', $hallId)->where('event_date', $hall['date'])
                    ->where('program', $hall['program'])->exists();
            if ($occupied) {
                throw new ConflictHttpException('Another customer has just taken this slot.');
            }
            $id = (string) Str::uuid();
            $expires = now()->addMinutes($minutes);
            DB::table('public_booking_holds')->insert([
                'id' => $id, 'company_id' => $companyId, 'hall_id' => $hallId,
                'event_date' => $hall['date'], 'program' => $hall['program'],
                'guests' => $hall['guests'], 'quote_hash' => $quote['quoteHash'],
                'quote' => json_encode($quote, JSON_THROW_ON_ERROR),
                'currency_code' => $hall['currencyCode'],
                'total_minor' => $hall['totalMinor'], 'deposit_minor' => $deposit,
                'customer_name' => $name, 'customer_email' => $email ?: null,
                'customer_phone' => $phone ?: null,
                'idempotency_key' => $idempotencyKey,
                'token_hash' => hash('sha256', $token),
                'token_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString($token),
                'expires_at' => $expires, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => null, 'action' => 'public_booking.hold_created',
                'entity_type' => 'public_booking_hold', 'entity_id' => $id,
                'before_values' => null,
                'after_values' => json_encode(['hallId' => $hallId,
                    'date' => $hall['date'], 'program' => $hall['program'],
                    'depositMinor' => $deposit, 'expiresAt' => $expires->toIso8601String()],
                    JSON_THROW_ON_ERROR),
                'context' => json_encode(['source' => 'public_website'], JSON_THROW_ON_ERROR),
            ]);

            return ['holdId' => $id, 'token' => $token,
                'expiresAt' => $expires->toIso8601String(), 'depositMinor' => $deposit];
        }, 3);
    }

    private function expire(string $companyId): void
    {
        $rows = DB::table('public_booking_holds')->where('company_id', $companyId)
            ->where('expires_at', '<=', now())->lockForUpdate()->get();
        foreach ($rows as $row) {
            DB::table('public_booking_hold_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'hold_id' => $row->id, 'action' => 'expired',
                'snapshot' => json_encode(['depositMinor' => (int) $row->deposit_minor,
                    'expiresAt' => $row->expires_at], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);
            DB::table('public_booking_holds')->where('id', $row->id)->delete();
        }
    }
    /** @return array{holdId:string,status:string,depositMinor:int} */
    public function cancel(string $companyId, string $token): array
    {
        if (! Str::isUuid($companyId) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid cancellation token.');
        }
        return DB::transaction(function () use ($companyId, $token): array {
            $hash = hash('sha256', $token);
            $hold = DB::table('public_booking_holds')->where('company_id', $companyId)
                ->where('token_hash', $hash)->lockForUpdate()->first();
            if (! $hold) {
                throw new ConflictHttpException('This hold is no longer available.');
            }
            DB::table('public_booking_hold_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'hold_id' => $hold->id, 'action' => 'cancelled',
                'snapshot' => json_encode(['depositMinor' => (int) $hold->deposit_minor],
                    JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);
            DB::table('public_booking_holds')->where('id', $hold->id)->delete();
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => null, 'action' => 'public_booking.hold_cancelled',
                'entity_type' => 'public_booking_hold', 'entity_id' => $hold->id,
                'before_values' => json_encode(['status' => 'active']),
                'after_values' => json_encode(['status' => 'cancelled']),
                'context' => json_encode(['source' => 'public_website'], JSON_THROW_ON_ERROR),
            ]);

            return ['holdId' => $hold->id, 'status' => 'cancelled',
                'depositMinor' => (int) $hold->deposit_minor];
        }, 3);
    }
}