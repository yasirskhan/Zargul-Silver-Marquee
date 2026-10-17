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

final class HallInventoryCatalogService
{
    public function __construct(private readonly TenantAccess $access) {}

    /** Create a hall-local catalog item without posting stock or cash. */
    public function create(
        User $actor,
        string $companyId,
        string $hallId,
        string $requestId,
        string $name,
        string $category,
        string $unit,
        int $minimumQuantity,
        string $reason,
    ): string {
        $name = trim($name);
        $category = trim($category);
        $reason = trim($reason);
        $normalizedKey = Str::slug($name);

        if (! Str::isUuid($requestId) || ! Str::isUuid($hallId)
            || ! Str::isUuid($companyId) || $name === ''
            || mb_strlen($name) > 160 || $normalizedKey === ''
            || mb_strlen($normalizedKey) > 180
            || $category === '' || mb_strlen($category) > 80
            || ! in_array($unit, ['piece', 'set', 'pair', 'kg', 'liter'], true)
            || $minimumQuantity < 0 || $minimumQuantity > 100000000
            || mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Supply an approved hall item name, category, unit, quantity and reason.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $hallId, $requestId, $name, $normalizedKey,
            $category, $unit, $minimumQuantity, $reason
        ): string {
            $hall = Hall::query()->where('company_id', $companyId)
                ->where('id', $hallId)->where('is_active', true)
                ->lockForUpdate()->first();
            if (! $hall || ! Auth::check()
                || (int) Auth::id() !== (int) $actor->getKey()
                || ! $this->access->canManageHallInventory($actor, $hall)) {
                throw new AuthorizationException('This hall inventory is not accessible for modification.');
            }

            $existing = DB::table('hall_inventory_items')->where('id', $requestId)->first();
            if ($existing) {
                if ($existing->company_id !== $companyId
                    || $existing->hall_id !== $hallId
                    || $existing->name !== $name
                    || $existing->normalized_key !== $normalizedKey
                    || $existing->category !== $category
                    || $existing->unit !== $unit
                    || (int) $existing->minimum_quantity !== $minimumQuantity
                    || $existing->creation_reason !== $reason) {
                    throw new ConflictHttpException('Inventory creation request conflicts with the existing item.');
                }
                return $requestId;
            }

            if (DB::table('hall_inventory_items')
                ->where('company_id', $companyId)->where('hall_id', $hallId)
                ->where('normalized_key', $normalizedKey)->exists()) {
                throw new ConflictHttpException('This hall already has an item with the same name.');
            }

            $time = now();
            DB::table('hall_inventory_items')->insert([
                'id' => $requestId,
                'company_id' => $companyId,
                'hall_id' => $hallId,
                'name' => $name,
                'normalized_key' => $normalizedKey,
                'category' => $category,
                'unit' => $unit,
                'minimum_quantity' => $minimumQuantity,
                'creation_reason' => $reason,
                'created_by' => $actor->getKey(),
                'created_at' => $time,
                'updated_at' => $time,
            ]);
            DB::table('hall_inventory_item_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'hall_id' => $hallId,
                'item_id' => $requestId,
                'action' => 'created',
                'after_values' => json_encode([
                    'name' => $name, 'category' => $category,
                    'unit' => $unit, 'minimumQuantity' => $minimumQuantity,
                ], JSON_THROW_ON_ERROR),
                'actor_id' => $actor->getKey(),
                'reason' => $reason,
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'actor_id' => $actor->getKey(),
                'action' => 'hall_inventory.item_created',
                'entity_type' => 'hall_inventory_item',
                'entity_id' => $requestId,
                'before_values' => json_encode([], JSON_THROW_ON_ERROR),
                'after_values' => json_encode([
                    'hallId' => $hallId, 'name' => $name,
                    'unit' => $unit, 'minimumQuantity' => $minimumQuantity,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);

            return $requestId;
        }, 3);
    }
}
