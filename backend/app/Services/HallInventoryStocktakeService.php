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

final class HallInventoryStocktakeService
{
    public function __construct(private readonly TenantAccess $access) {}

    /**
     * Submit counted physical evidence for one hall, including separately
     * owned and temporarily borrowed items. Does NOT post inventory balances.
     * Later reconciliation must account for stock receipts and open loans.
     */
    public function submit(
        User $actor, string $companyId, string $hallId, string $requestId,
        string $auditKind, array $lines, string $reason,
    ): string {
        $reason = trim($reason);
        if (! Str::isUuid($companyId) || ! Str::isUuid($hallId)
            || ! Str::isUuid($requestId)
            || ! in_array($auditKind, ['opening', 'post_event', 'monthly', 'quarterly', 'ad_hoc'], true)
            || count($lines) < 1 || count($lines) > 100
            || mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Invalid hall inventory count request.');
        }
        $rows = [];
        foreach ($lines as $line) {
            if (! is_array($line) || array_is_list($line)
                || count($line) !== 4
                || array_diff(array_keys($line), ['itemId', 'ownership', 'usableCount', 'damagedCount'])
                || ! Str::isUuid($line['itemId'] ?? '')
                || ! in_array($line['ownership'] ?? null, ['owned', 'borrowed'], true)
                || ! is_int($line['usableCount'] ?? null)
                || ! is_int($line['damagedCount'] ?? null)
                || $line['usableCount'] < 0 || $line['damagedCount'] < 0
                || $line['usableCount'] > 100000000 || $line['damagedCount'] > 100000000) {
                throw new InvalidArgumentException('Every inventory count row needs one valid item, ownership and whole-number count.');
            }
            $key = $line['itemId'].'|'.$line['ownership'];
            if (isset($rows[$key])) {
                throw new InvalidArgumentException('Duplicate hall item/ownership count row.');
            }
            $rows[$key] = [
                'itemId' => $line['itemId'],
                'ownership' => $line['ownership'],
                'usableCount' => $line['usableCount'],
                'damagedCount' => $line['damagedCount'],
            ];
        }
        ksort($rows, SORT_STRING);
        $fingerprint = hash('sha256', json_encode([
            'company' => $companyId, 'hall' => $hallId, 'auditKind' => $auditKind,
            'lines' => array_values($rows), 'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $actor, $companyId, $hallId, $requestId, $auditKind,
            $rows, $reason, $fingerprint
        ): string {
            $hall = Hall::query()->where('company_id', $companyId)
                ->where('id', $hallId)->where('is_active', true)
                ->lockForUpdate()->first();
            if (! $hall || ! Auth::check()
                || (int) Auth::id() !== (int) $actor->getKey()
                || ! $this->access->canManageHallInventory($actor, $hall)) {
                throw new AuthorizationException('This hall stock count is not accessible.');
            }
            $existing = DB::table('hall_inventory_stocktakes')->where('id', $requestId)->first();
            if ($existing) {
                if ($existing->company_id !== $companyId
                    || $existing->hall_id !== $hallId
                    || (int) $existing->counted_by !== (int) $actor->getKey()
                    || $existing->audit_kind !== $auditKind
                    || $existing->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('Stocktake request fingerprint changed.');
                }
                return $requestId;
            }

            $itemIds = array_values(array_unique(array_map(
                static fn ($line) => $line['itemId'], $rows)));
            sort($itemIds, SORT_STRING);
            $existingItems = DB::table('hall_inventory_items')
                ->where('company_id', $companyId)->where('hall_id', $hallId)
                ->where('is_active', true)->whereIn('id', $itemIds)
                ->orderBy('id')->lockForUpdate()->pluck('id')->all();
            if (count($existingItems) !== count($itemIds)) {
                throw new AuthorizationException('Count rows must use active catalog items owned by the selected hall.');
            }

            DB::table('hall_inventory_stocktakes')->insert([
                'id' => $requestId, 'company_id' => $companyId, 'hall_id' => $hallId,
                'audit_kind' => $auditKind, 'request_fingerprint' => $fingerprint,
                'reason' => $reason, 'counted_by' => $actor->getKey(),
            ]);
            foreach ($rows as $line) {
                DB::table('hall_inventory_stocktake_lines')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId,
                    'hall_id' => $hallId, 'stocktake_id' => $requestId,
                    'item_id' => $line['itemId'], 'ownership' => $line['ownership'],
                    'usable_count' => $line['usableCount'],
                    'damaged_count' => $line['damagedCount'],
                ]);
            }
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(),
                'action' => 'hall_inventory.physical_count_submitted',
                'entity_type' => 'hall_inventory_stocktake', 'entity_id' => $requestId,
                'before_values' => json_encode([], JSON_THROW_ON_ERROR),
                'after_values' => json_encode([
                    'hallId' => $hallId, 'auditKind' => $auditKind,
                    'lineCount' => count($rows), 'fingerprint' => $fingerprint,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
            return $requestId;
        }, 3);
    }
}
