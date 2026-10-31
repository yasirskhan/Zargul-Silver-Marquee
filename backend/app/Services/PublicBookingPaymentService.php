<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Trusted synthetic verifier for isolated acceptance only; never a customer flag. */
final class PublicBookingPaymentService
{
    public function verifySynthetic(string $companyId, string $holdId, string $reference,
        int $amountMinor, string $currencyCode, string $verifier, string $signature): array
    {
        $secret = config('public_booking.synthetic_verifier_secret');
        if (! is_string($secret) || strlen($secret) < 32) {
            throw new ConflictHttpException('Synthetic payment verification is not enabled.');
        }
        if (! Str::isUuid($companyId) || ! Str::isUuid($holdId)
            || preg_match('/^[A-Za-z0-9._-]{6,120}$/D', $reference) !== 1
            || $amountMinor < 1 || preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1
            || mb_strlen($verifier) < 3 || mb_strlen($verifier) > 120) {
            throw new InvalidArgumentException('Invalid synthetic payment evidence.');
        }
        $canonical = implode('|', [$companyId, $holdId, $reference,
            (string) $amountMinor, $currencyCode, $verifier]);
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (! hash_equals($expected, strtolower($signature))) {
            throw new ConflictHttpException('Synthetic payment evidence could not be authenticated.');
        }

        return DB::transaction(function () use ($companyId, $holdId, $reference,
            $amountMinor, $currencyCode, $verifier, $canonical): array {
            $hold = DB::table('public_booking_holds')->where('company_id', $companyId)
                ->where('id', $holdId)->lockForUpdate()->first();
            if (! $hold || $hold->expires_at <= now()) {
                throw new ConflictHttpException('The hold is expired or unavailable.');
            }
            if ((int) $hold->deposit_minor !== $amountMinor
                || $hold->currency_code !== $currencyCode) {
                throw new ConflictHttpException('Payment amount or currency does not match the approved deposit.');
            }
            $existing = DB::table('public_booking_payment_evidence')
                ->where('reference', $reference)->first();
            if ($existing) {
                if ($existing->company_id !== $companyId || $existing->hold_id !== $holdId
                    || (int) $existing->amount_minor !== $amountMinor
                    || $existing->currency_code !== $currencyCode) {
                    throw new ConflictHttpException('Payment evidence reference was reused.');
                }
                return ['evidenceId' => $existing->id, 'verifiedAt' => $existing->verified_at];
            }
            $id = (string) Str::uuid();
            DB::table('public_booking_payment_evidence')->insert([
                'id' => $id, 'company_id' => $companyId, 'hold_id' => $holdId,
                'provider' => 'synthetic_test', 'reference' => $reference,
                'amount_minor' => $amountMinor, 'currency_code' => $currencyCode,
                'verifier' => $verifier, 'verified_at' => now(),
                'evidence' => json_encode(['canonical' => $canonical,
                    'signature' => hash_hmac('sha256', $canonical, (string) config('public_booking.synthetic_verifier_secret'))],
                    JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['evidenceId' => $id, 'verifiedAt' => now()->toIso8601String()];
        }, 3);
    }
}