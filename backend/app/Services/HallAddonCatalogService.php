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

final class HallAddonCatalogService
{
    private const FIELDS = ['code', 'name', 'category', 'chargeUnit', 'rateMinor', 'enabled'];

    private const CATEGORIES = [
        'catering', 'music', 'lighting', 'bridal_entry', 'stage', 'photography', 'other',
    ];

    private const UNITS = ['flat', 'per_guest', 'per_unit'];

    public function __construct(private readonly TenantAccess $access) {}

    /**
     * Hall-scoped catalog options only. No price is charged until a later quote
     * service selects a versioned, owner-approved package and snapshots terms.
     * Per-guest/per-unit semantics remain subject to ADR-0002 release approval.
     */
    public function configure(
        User $actor, string $companyId, string $hallId,
        array $items, string $currencyCode, int $expectedRevision, string $reason,
    ): int {
        $candidate = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        $this->authorize($actor, $candidate);
        $this->validateItems($items, $currencyCode);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Add-on catalog changes require a reason.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $hallId, $items, $currencyCode, $expectedRevision, $reason
        ): int {
            $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
                ->where('is_active', true)->lockForUpdate()->first();
            $this->authorize($actor, $hall);

            $live = (int) $hall->addon_catalog_revision;
            if ($expectedRevision < 0 || $live !== $expectedRevision) {
                throw new ConflictHttpException('Hall add-on catalog changed; reload before saving.');
            }
            $old = $live > 0
                ? DB::table('hall_addon_catalog_revisions')->where('company_id', $companyId)
                    ->where('hall_id', $hallId)->where('revision_number', $live)->first()
                : null;
            if ($live > 0 && ! $old) {
                throw new ConflictHttpException('Previous approved add-on catalog is missing.');
            }

            $next = $live + 1;
            $id = (string) Str::uuid();
            DB::table('hall_addon_catalog_revisions')->insert([
                'id' => $id, 'company_id' => $companyId, 'hall_id' => $hallId,
                'revision_number' => $next, 'currency_code' => $currencyCode,
                'items' => json_encode($items, JSON_THROW_ON_ERROR),
                'changed_by' => $actor->getKey(), 'reason' => $reason,
            ]);
            DB::table('halls')->where('company_id', $companyId)->where('id', $hallId)
                ->update(['addon_catalog_revision' => $next, 'updated_at' => now()]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'hall.addon_catalog_updated',
                'entity_type' => 'hall', 'entity_id' => $hallId,
                'before_values' => json_encode([
                    'revision' => $live, 'currencyCode' => $old?->currency_code,
                    'items' => $old ? json_decode($old->items, true, 512, JSON_THROW_ON_ERROR) : null,
                ], JSON_THROW_ON_ERROR),
                'after_values' => json_encode([
                    'revision' => $next, 'revisionId' => $id, 'currencyCode' => $currencyCode,
                    'items' => $items,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);

            return $next;
        }, 3);
    }

    public function current(User $actor, string $companyId, string $hallId): ?array
    {
        $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail() || ! $this->access->canViewHall($actor, $hall)) {
            throw new AuthorizationException('This hall is not accessible.');
        }

        $revision = (int) $hall->addon_catalog_revision;
        if ($revision === 0) {
            return null;
        }
        $saved = DB::table('hall_addon_catalog_revisions')->where('company_id', $companyId)
            ->where('hall_id', $hallId)->where('revision_number', $revision)->first();
        if (! $saved) {
            throw new ConflictHttpException('Hall add-on catalog needs administrative repair.');
        }

        return [
            'revision' => $revision,
            'currencyCode' => $saved->currency_code,
            'items' => json_decode($saved->items, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function authorize(User $actor, ?Hall $hall): void
    {
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail() || ! $this->access->canConfigureHall($actor, $hall)) {
            throw new AuthorizationException('You cannot change this hall add-on catalog.');
        }
    }

    private function validateItems(array $items, string $currencyCode): void
    {
        if (! preg_match('/\A[A-Z]{3}\z/', $currencyCode)
            || ! array_is_list($items) || count($items) > 100) {
            throw new InvalidArgumentException('An add-on catalog must be a list of at most 100 items and a valid currency code.');
        }
        $codes = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item) || count($item) !== count(self::FIELDS)
                || array_diff(array_keys($item), self::FIELDS)
                || array_diff(self::FIELDS, array_keys($item))) {
                throw new InvalidArgumentException('Each add-on requires exactly six typed fields.');
            }
            if (! is_string($item['code'])
                || ! preg_match('/\A[a-z][a-z0-9_-]{1,39}\z/', $item['code'])
                || isset($codes[$item['code']])) {
                throw new InvalidArgumentException('Add-on codes must be unique and safe lowercase identifiers.');
            }
            $codes[$item['code']] = true;
            $name = $item['name'];
            if (! is_string($name) || mb_strlen(trim($name)) < 2 || mb_strlen($name) > 120
                || strip_tags($name) !== $name
                || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                throw new InvalidArgumentException('Add-on names must be short plain text.');
            }
            if (! in_array($item['category'], self::CATEGORIES, true)
                || ! in_array($item['chargeUnit'], self::UNITS, true)
                || ! is_int($item['rateMinor']) || $item['rateMinor'] < 0
                || $item['rateMinor'] > 100_000_000_000
                || ! is_bool($item['enabled'])) {
                throw new InvalidArgumentException('Add-on category, charging unit, rate or enabled flag is invalid.');
            }
        }
    }
}
