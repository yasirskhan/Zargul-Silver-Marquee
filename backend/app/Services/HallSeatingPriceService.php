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

final class HallSeatingPriceService
{
    private const FIELDS = [
        'capacity', 'includedChairs', 'includedTables',
        'extraChairRateMinor', 'extraTableRateMinor',
    ];

    public function __construct(private readonly TenantAccess $access) {}

    /**
     * Snapshot approved hall seating capacity and price extras together.
     * These are catalog rules, not a booking or an invoice; amounts are integer
     * minor units and a pricing engine must snapshot them at quote approval.
     */
    public function configure(
        User $actor, string $companyId, string $hallId,
        array $config, string $currencyCode, int $expectedRevision, string $reason,
    ): int {
        $candidate = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        if (! $candidate || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail() || ! $this->access->canConfigureHall($actor, $candidate)) {
            throw new AuthorizationException('You cannot change this hall seating capacity or prices.');
        }

        $this->validateConfig($config, $currencyCode);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A reason for seating or price changes is required.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $hallId, $config, $currencyCode, $expectedRevision, $reason
        ): int {
            $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
                ->where('is_active', true)->lockForUpdate()->first();
            if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
                || ! $actor->hasVerifiedEmail() || ! $this->access->canConfigureHall($actor, $hall)) {
                throw new AuthorizationException('You cannot change this hall seating capacity or prices.');
            }

            $live = (int) $hall->seating_price_revision;
            if ($expectedRevision < 0 || $live !== $expectedRevision) {
                throw new ConflictHttpException('Hall seating rules changed; reload before saving.');
            }

            $previous = $live > 0
                ? DB::table('hall_seating_price_revisions')->where('company_id', $companyId)
                    ->where('hall_id', $hallId)->where('revision_number', $live)->first()
                : null;
            if ($live > 0 && ! $previous) {
                throw new ConflictHttpException('Previously approved seating configuration is missing.');
            }
            // Detect direct catalog edits made outside the audited versioned workflow.
            if ($previous && (
                (int) $hall->capacity !== (int) $previous->capacity
                || (int) $hall->included_chairs !== (int) $previous->included_chairs
                || (int) $hall->included_tables !== (int) $previous->included_tables
            )) {
                throw new ConflictHttpException('Hall seating data changed outside versioned settings; administrative review required.');
            }

            $next = $live + 1;
            $id = (string) Str::uuid();
            DB::table('hall_seating_price_revisions')->insert([
                'id' => $id,
                'company_id' => $companyId,
                'hall_id' => $hallId,
                'revision_number' => $next,
                'capacity' => $config['capacity'],
                'included_chairs' => $config['includedChairs'],
                'included_tables' => $config['includedTables'],
                'extra_chair_rate_minor' => $config['extraChairRateMinor'],
                'extra_table_rate_minor' => $config['extraTableRateMinor'],
                'currency_code' => $currencyCode,
                'changed_by' => $actor->getKey(),
                'reason' => $reason,
            ]);
            DB::table('halls')->where('company_id', $companyId)->where('id', $hallId)->update([
                'capacity' => $config['capacity'],
                'included_chairs' => $config['includedChairs'],
                'included_tables' => $config['includedTables'],
                'seating_price_revision' => $next,
                'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'actor_id' => $actor->getKey(),
                'action' => 'hall.seating_prices_updated',
                'entity_type' => 'hall',
                'entity_id' => $hallId,
                'before_values' => json_encode([
                    'revision' => $live,
                    'capacity' => $previous ? (int) $previous->capacity : (int) $hall->capacity,
                    'includedChairs' => $previous ? (int) $previous->included_chairs : (int) $hall->included_chairs,
                    'includedTables' => $previous ? (int) $previous->included_tables : (int) $hall->included_tables,
                    'extraChairRateMinor' => $previous ? (int) $previous->extra_chair_rate_minor : null,
                    'extraTableRateMinor' => $previous ? (int) $previous->extra_table_rate_minor : null,
                    'currencyCode' => $previous?->currency_code,
                ], JSON_THROW_ON_ERROR),
                'after_values' => json_encode([
                    'revision' => $next, 'revisionId' => $id, 'config' => $config,
                    'currencyCode' => $currencyCode,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);

            return $next;
        }, 3);
    }

    /** No approved rate revision means pricing is not configured for booking. */
    public function current(User $actor, string $companyId, string $hallId): ?array
    {
        $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail() || ! $this->access->canViewHall($actor, $hall)) {
            throw new AuthorizationException('This hall is not accessible.');
        }
        $revision = (int) $hall->seating_price_revision;
        if ($revision === 0) {
            return null;
        }

        $saved = DB::table('hall_seating_price_revisions')
            ->where('company_id', $companyId)->where('hall_id', $hallId)
            ->where('revision_number', $revision)->first();
        if (! $saved
            || (int) $hall->capacity !== (int) $saved->capacity
            || (int) $hall->included_chairs !== (int) $saved->included_chairs
            || (int) $hall->included_tables !== (int) $saved->included_tables) {
            throw new ConflictHttpException('Hall seating rules need administrative review.');
        }

        return [
            'revision' => $revision,
            'currencyCode' => $saved->currency_code,
            'config' => [
                'capacity' => (int) $saved->capacity,
                'includedChairs' => (int) $saved->included_chairs,
                'includedTables' => (int) $saved->included_tables,
                'extraChairRateMinor' => (int) $saved->extra_chair_rate_minor,
                'extraTableRateMinor' => (int) $saved->extra_table_rate_minor,
            ],
        ];
    }

    private function validateConfig(array $config, string $currencyCode): void
    {
        if (! preg_match('/\A[A-Z]{3}\z/', $currencyCode)
            || array_is_list($config) || count($config) !== count(self::FIELDS)
            || array_diff(array_keys($config), self::FIELDS)
            || array_diff(self::FIELDS, array_keys($config))) {
            throw new InvalidArgumentException('Configure approved seating fields and an uppercase currency code.');
        }

        foreach (self::FIELDS as $field) {
            if (! is_int($config[$field]) || $config[$field] < 0) {
                throw new InvalidArgumentException('Seating quantities and unit prices must be nonnegative integers.');
            }
        }
        if ($config['capacity'] < 1 || $config['capacity'] > 100000
            || $config['includedChairs'] > $config['capacity']
            || $config['includedTables'] > $config['capacity']
            || $config['extraChairRateMinor'] > 100_000_000_000
            || $config['extraTableRateMinor'] > 100_000_000_000) {
            throw new InvalidArgumentException('Seating capacity, included quantities, or unit prices are outside approved limits.');
        }
    }
}
