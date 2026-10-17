<?php

namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HallInventoryLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Panel Inventory Business',
            'slug' => 'panel-inventory-business']);
        $hall = Hall::create(['company_id' => $company->id,
            'name' => 'Marquee A', 'slug' => 'inventory-panel-a', 'capacity' => 300]);
        $other = Hall::create(['company_id' => $company->id,
            'name' => 'Marquee B', 'slug' => 'inventory-panel-b', 'capacity' => 250]);
        $manager = User::create(['name' => 'Stock Manager',
            'email' => 'inventory-panel-manager@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create(['company_id' => $company->id,
            'membership_id' => $membership->id, 'hall_id' => $hall->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($manager);
        return [$company, $hall, $other, $manager, $membership];
    }

    public function test_inventory_catalog_page_hidden_by_default_and_manager_sees_only_assigned_hall(): void
    {
        [$company, $hall, $other] = $this->fixture();
        $this->assertFalse(HallInventory::canAccess());
        config()->set('inventory_controls.catalog_enabled', true);
        $this->assertTrue(HallInventory::canAccess());
        Livewire::test(HallInventory::class)
            ->assertSee('Marquee A')
            ->assertDontSee('Marquee B')
            ->assertSee('No inventory catalog items');
    }

    public function test_manager_creates_real_per_hall_item_and_submits_multiple_physical_counts(): void
    {
        [$company, $hall] = $this->fixture();
        config()->set('inventory_controls.catalog_enabled', true);
        config()->set('inventory_controls.stocktakes_enabled', true);

        Livewire::test(HallInventory::class)
            ->set('itemName', 'Water Jugs')
            ->set('itemCategory', 'Serving')
            ->set('itemUnit', 'piece')
            ->set('minimumQuantity', '35')
            ->set('createReason', 'Approved hall item catalog')
            ->call('createItem')->assertHasNoErrors()
            ->assertSee('Water Jugs')
            ->assertSee('35');

        $id = DB::table('hall_inventory_items')->where('hall_id', $hall->id)
            ->where('name', 'Water Jugs')->value('id');
        $this->assertNotNull($id);
        Livewire::test(HallInventory::class)
            ->call('addCountRow')
            ->set('countRows.0.itemId', $id)
            ->set('countRows.0.ownership', 'owned')
            ->set('countRows.0.usableCount', '30')
            ->set('countRows.0.damagedCount', '2')
            ->call('addCountRow')
            ->set('countRows.1.itemId', $id)
            ->set('countRows.1.ownership', 'borrowed')
            ->set('countRows.1.usableCount', '5')
            ->set('countRows.1.damagedCount', '0')
            ->set('auditKind', 'post_event')
            ->set('auditReason', 'After party manager count recorded')
            ->call('submitCount')->assertHasNoErrors()
            ->assertSee('Count submitted for review');

        $this->assertSame(1, DB::table('hall_inventory_stocktakes')->count());
        $this->assertSame(2, DB::table('hall_inventory_stocktake_lines')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_manager_role_revocation_blocks_live_hall_inventory_actions(): void
    {
        [$company, $hall, , , $membership] = $this->fixture();
        config()->set('inventory_controls.catalog_enabled', true);
        $page = Livewire::test(HallInventory::class)->assertSee('Marquee A');
        DB::table('company_memberships')->where('id', $membership->id)
            ->update(['is_active' => false]);
        $page->call('reloadCatalog')->assertForbidden();
        $this->assertSame(0, DB::table('hall_inventory_items')->count());
    }
}
