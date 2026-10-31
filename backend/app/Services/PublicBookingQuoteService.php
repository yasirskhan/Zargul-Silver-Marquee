<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Public, server-authoritative quote from published pricing revisions. */
final class PublicBookingQuoteService
{
    private const PROGRAMS = ['morning', 'noon', 'evening'];

    /** @return array{companyId:string,date:string,program:string,guests:int,quoteHash:string,halls:array<int,array<string,mixed>>} */
    public function quote(string $companyId, string $date, string $program, int $guests,
        array $addonCodes = []): array
    {
        if (! Str::isUuid($companyId) || ! $this->validDate($date)
            || ! in_array($program, self::PROGRAMS, true) || $guests < 1 || $guests > 100000
            || count($addonCodes) > 20) {
            throw new InvalidArgumentException('Enter a valid event date, program and guest count.');
        }
        $addonCodes = array_values(array_unique(array_filter($addonCodes,
            fn ($code): bool => is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code))));
        if (count($addonCodes) > 20) {
            throw new InvalidArgumentException('Too many add-ons selected.');
        }
        $halls = DB::table('halls')->where('company_id', $companyId)
            ->where('is_active', true)->where('is_bookable', true)
            ->where('capacity', '>=', $guests)->orderBy('name')->get();
        $results = [];
        foreach ($halls as $hall) {
            $availability = $this->availability($companyId, $hall->id, $date, $program);
            if (! $availability) {
                continue;
            }
            $guest = app(HallGuestPricingService::class)->quote($companyId, $hall->id, $guests);
            $programRules = $this->programRule($companyId, $hall->id, $program);
            $addons = $this->addons($companyId, $hall->id, $addonCodes, $guests);
            $lines = [[
                'code' => 'guest_tier', 'label' => 'Guest package',
                'amountMinor' => $guest['priceMinor'],
            ], [
                'code' => 'program_'.$program, 'label' => ucfirst($program).' program',
                'amountMinor' => $programRules['rentalRateMinor'],
            ]];
            foreach ($addons as $addon) {
                $lines[] = $addon;
            }
            $total = array_sum(array_column($lines, 'amountMinor'));
            $results[] = [
                'hallId' => $hall->id, 'hallName' => $hall->name,
                'capacity' => (int) $hall->capacity, 'currencyCode' => $guest['currencyCode'],
                'lines' => $lines, 'totalMinor' => $total,
                'guestPricingRevision' => $guest['revision'],
                'programPricingRevision' => $programRules['revision'],
                'addonCatalogRevision' => $this->addonRevision($companyId, $hall->id),
                'date' => $date, 'program' => $program, 'guests' => $guests,
                'addonCodes' => $addonCodes,
            ];
        }
        if ($results === []) {
            throw new ConflictHttpException('No bookable hall is available for that date and program.');
        }
        $hash = hash('sha256', json_encode(array_map(fn (array $h): array => [
            $h['hallId'], $h['totalMinor'], $h['guestPricingRevision'],
            $h['programPricingRevision'], $h['addonCatalogRevision'],
        ], $results), JSON_THROW_ON_ERROR));

        return ['companyId' => $companyId, 'date' => $date, 'program' => $program,
            'guests' => $guests, 'quoteHash' => $hash, 'halls' => $results];
    }

    /** Recalculate and reject stale pricing or tampered totals. */
    public function revalidate(array $quote, string $hallId): array
    {
        $fresh = $this->quote((string) ($quote['companyId'] ?? ''), (string) ($quote['date'] ?? ''),
            (string) ($quote['program'] ?? ''), (int) ($quote['guests'] ?? 0),
            is_array($quote['addonCodes'] ?? null) ? $quote['addonCodes'] : []);
        if (! hash_equals($fresh['quoteHash'], (string) ($quote['quoteHash'] ?? ''))) {
            throw new ConflictHttpException('Pricing or availability changed; restart your quote.');
        }
        foreach ($fresh['halls'] as $hall) {
            if ($hall['hallId'] === $hallId) {
                return ['quote' => $fresh, 'hall' => $hall];
            }
        }
        throw new ConflictHttpException('The selected hall is not available.');
    }

    private function availability(string $companyId, string $hallId, string $date, string $program): bool
    {
        $held = DB::table('hall_slot_claims')->where('company_id', $companyId)
            ->where('hall_id', $hallId)->where('event_date', $date)->where('program', $program)
            ->where(fn ($q) => $q->where('status', 'confirmed')
                ->orWhere(fn ($q) => $q->where('status', 'hold')
                    ->where('expires_at', '>', now())))->exists();
        if ($held) {
            return false;
        }

        return ! DB::table('venue_reservations')->where('company_id', $companyId)
            ->where('hall_id', $hallId)->where('event_date', $date)->where('program', $program)
            ->where('status', 'confirmed')->exists();
    }

    private function programRule(string $companyId, string $hallId, string $program): array
    {
        $revision = DB::table('halls')->where('company_id', $companyId)
            ->where('id', $hallId)->value('program_rules_revision');
        if (! is_int($revision) || $revision < 1) {
            $revision = (int) $revision;
        }
        if ($revision < 1) {
            throw new ConflictHttpException('Program pricing is not configured for this hall.');
        }
        $row = DB::table('hall_program_rule_revisions')->where('company_id', $companyId)
            ->where('hall_id', $hallId)->where('revision_number', $revision)->first();
        if (! $row) {
            throw new ConflictHttpException('Approved program pricing is unavailable.');
        }
        $rules = json_decode($row->rules, true, 32, JSON_THROW_ON_ERROR);
        $rule = $rules[$program] ?? null;
        if (! is_array($rule) || ! is_int($rule['rentalRateMinor'] ?? null)
            || $rule['rentalRateMinor'] < 0) {
            throw new ConflictHttpException('Program pricing is invalid.');
        }

        return ['rentalRateMinor' => $rule['rentalRateMinor'], 'revision' => $revision];
    }

    private function addons(string $companyId, string $hallId, array $codes, int $guests): array
    {
        if ($codes === []) {
            return [];
        }
        $revision = $this->addonRevision($companyId, $hallId);
        if ($revision < 1) {
            throw new ConflictHttpException('Approved add-ons are unavailable.');
        }
        $row = DB::table('hall_addon_catalog_revisions')->where('company_id', $companyId)
            ->where('hall_id', $hallId)->where('revision_number', $revision)->first();
        $items = $row ? json_decode($row->items, true, 32, JSON_THROW_ON_ERROR) : [];
        $lines = [];
        foreach ($items as $item) {
            if (! in_array($item['code'] ?? null, $codes, true) || ($item['enabled'] ?? false) !== true) {
                continue;
            }
            $rate = (int) $item['rateMinor'];
            $amount = match ($item['chargeUnit']) {
                'per_guest' => $rate * $guests,
                'per_unit', 'flat' => $rate,
                default => throw new ConflictHttpException('Add-on pricing is invalid.'),
            };
            $lines[] = ['code' => $item['code'], 'label' => $item['name'],
                'amountMinor' => $amount];
        }
        if (count($lines) !== count($codes)) {
            throw new ConflictHttpException('An add-on is no longer approved.');
        }

        return $lines;
    }

    private function addonRevision(string $companyId, string $hallId): int
    {
        return (int) DB::table('halls')->where('company_id', $companyId)
            ->where('id', $hallId)->value('addon_catalog_revision');
    }

    private function validDate(string $date): bool
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $value !== false && $value->format('Y-m-d') === $date;
    }
}