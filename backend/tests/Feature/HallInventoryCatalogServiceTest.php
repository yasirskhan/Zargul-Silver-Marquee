<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Inventory Venue', 'slug' => 'inventory-venue']);
        $first = Hall::create(['company_id' => $company->id, 'name' => 'Hall A',
            'slug' => 'inventory-a', 'capacity' => 500]);
        $second = Hall::create(['company_id' => $company->id, 'name' => 'Hall B',
            'slug' => 'inventory-b', 'capacity' => 300]);
        $manager = User::create(['name' => 'Manager', 'email' => 'inventory-manager@example.test',
            'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create(['company_id' => $company->id,
            'membership_id' => $membership->id, 'hall_id' => $first->id]);

        return [$company, $first, $second, $manager, $membership];
    }

    public function test_assigned_manager_creates_multiple_hall_owned_items_with_audit_without_increasing_stock(): void
    {
        [$company, $hall, , $manager] = $this->fixture();
        $this->be($manager);
        $service = app(HallInventoryCatalogService::class);
        $spoons = (string) Str::uuid();
        $chairs = (string) Str::uuid();

        $this->assertSame($spoons, $service->create(
            $manager, $company->id, $hall->id, $spoons,
            'Dinner Spoons', 'Cutlery', 'piece', 180, 'Approved opening catalog'));
        $this->assertSame($spoons, $service->create(
            $manager, $company->id, $hall->id, $spoons,
            'Dinner Spoons', 'Cutlery', 'piece', 180, 'Approved opening catalog'));
        $service->create($manager, $company->id, $hall->id, $chairs,
            'Chairs', 'Furniture', 'piece', 500, 'Approved opening catalog');

        $this->assertSame(2, DB::table('hall_inventory_items')->where('hall_id', $hall->id)->count());
        $this->assertSame(2, DB::table('hall_inventory_item_events')->count());
        $this->assertSame(2, DB::table('audit_events')
            ->where('action', 'hall_inventory.item_created')->count());
        $this->assertSame('dinner-spoons', DB::table('hall_inventory_items')
            ->where('id', $spoons)->value('normalized_key'));
        $this->assertSame(0, DB::table('finance_journals')->count(),
            'Catalog-only item creation must never post a financial or stock-ownership purchase.');
    }

    public function test_manager_cannot_create_catalog_entry_for_unassigned_or_other_company_hall(): void
    {
        [$company, , $second, $manager] = $this->fixture();
        $this->be($manager);
        $service = app(HallInventoryCatalogService::class);
        try {
            $service->create($manager, $company->id, $second->id, (string) Str::uuid(),
                'Glasses', 'Glassware', 'piece', 10, 'Other hall denied');
            $this->fail('Unassigned hall was allowed.');
        } catch (AuthorizationException) {}
        $other = Company::create(['name' => 'Other Company', 'slug' => 'other-inventory']);
        $external = Hall::create(['company_id' => $other->id, 'name' => 'Outside',
            'slug' => 'outside-hall', 'capacity' => 100]);
        try {
            $service->create($manager, $other->id, $external->id, (string) Str::uuid(),
                'Glasses', 'Glassware', 'piece', 10, 'Outside company denied');
            $this->fail('Cross-company hall was allowed.');
        } catch (AuthorizationException) {}
        $this->assertSame(0, DB::table('hall_inventory_items')->count());
    }

    public function test_saved_membership_revocation_and_conflicting_retry_fail_closed(): void
    {
        [$company, $hall, , $manager, $membership] = $this->fixture();
        $this->be($manager);
        $service = app(HallInventoryCatalogService::class);
        $id = (string) Str::uuid();
        $service->create($manager, $company->id, $hall->id, $id,
            'Water Jugs', 'Serving', 'piece', 30, 'Opening catalog');
        try {
            $service->create($manager, $company->id, $hall->id, $id,
                'Water Jugs', 'Serving', 'piece', 50, 'Changing existing request');
            $this->fail('Conflicting request id was accepted.');
        } catch (ConflictHttpException) {}
        DB::table('company_memberships')->where('id', $membership->id)
            ->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $service->create($manager, $company->id, $hall->id, (string) Str::uuid(),
            'Extra Cups', 'Crockery', 'piece', 5, 'Revoked manager denied');
    }

    public function test_direct_cross_hall_item_and_immutable_history_rewrites_rejected(): void
    {
        [$company, $hall, $second, $manager] = $this->fixture();
        $this->be($manager);
        $id = (string) Str::uuid();
        app(HallInventoryCatalogService::class)->create(
            $manager, $company->id, $hall->id, $id,
            'Dinner Plates', 'Crockery', 'piece', 300, 'Opening catalog');
        $this->expectException(QueryException::class);
        DB::table('hall_inventory_items')->where('id', $id)->update(['hall_id' => $second->id]);
    }

    public function test_catalog_creation_history_cannot_be_deleted(): void
    {
        [$company, $hall, , $manager] = $this->fixture();
        $this->be($manager);
        $id = (string) Str::uuid();
        app(HallInventoryCatalogService::class)->create(
            $manager, $company->id, $hall->id, $id,
            'Dinner Plates', 'Crockery', 'piece', 300, 'Opening catalog');
        $this->expectException(QueryException::class);
        DB::table('hall_inventory_item_events')->where('item_id', $id)->delete();
    }

    public function test_cross_company_schema_forgery_refused_by_composite_foreign_key(): void
    {
        [$company, $hall, , $manager] = $this->fixture();
        $other = Company::create(['name' => 'Other Inventory', 'slug' => 'other-inventory-fk']);
        $this->expectException(QueryException::class);
        DB::table('hall_inventory_items')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $other->id,
            'hall_id' => $hall->id, 'name' => 'Forged spoons',
            'normalized_key' => 'forged-spoons',
            'category' => 'Cutlery', 'unit' => 'piece',
            'minimum_quantity' => 0, 'creation_reason' => 'Forged stock',
            'created_by' => $manager->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
