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
            ->assertSee('Count submitted for review')
            ->assertSee('Physical count history (evidence only)')
            ->assertSee('Water Jugs')
            ->assertSee('30 usable, 2 damaged')
            ->assertSee('5 usable, 0 damaged');

        $this->assertSame(1, DB::table('hall_inventory_stocktakes')->count());
        $this->assertSame(2, DB::table('hall_inventory_stocktake_lines')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_manager_builds_multi_line_internal_hall_loan_draft_from_inventory_window(): void
    {
        [$company, $hall, $other, $manager] = $this->fixture();
        config()->set('inventory_controls.catalog_enabled', true);
        config()->set('inventory_controls.loan_drafts_enabled', true);
        $a = (string) \Illuminate\Support\Str::uuid();
        $b = (string) \Illuminate\Support\Str::uuid();
        $catalog = app(\App\Services\HallInventoryCatalogService::class);
        $catalog->create($manager, $company->id, $hall->id, $a,
            'Chairs', 'Furniture', 'piece', 500, 'Hall A catalog');
        $catalog->create($manager, $company->id, $hall->id, $b,
            'Glasses', 'Glassware', 'piece', 200, 'Hall A catalog');
        Livewire::test(HallInventory::class)
            ->call('addLoanRow')
            ->set('loanRows.0.itemId', $a)
            ->set('loanRows.0.quantity', '50')
            ->call('addLoanRow')
            ->set('loanRows.1.itemId', $b)
            ->set('loanRows.1.quantity', '100')
            ->set('loanCounterpartHallId', $other->id)
            ->set('loanReturnDate', '2027-04-30')
            ->set('loanPurpose', 'Hall B evening wedding request')
            ->call('submitLoanDraft')->assertHasNoErrors()
            ->assertSee('Multi-item loan request saved')
            ->assertSee('Saved borrowing requests (draft only)')
            ->assertSee('Hall B evening wedding request')
            ->assertSee('50 piece')
            ->assertSee('100 piece');
        $this->assertSame(1, DB::table('hall_inventory_loan_drafts')->count());
        $this->assertSame(2, DB::table('hall_inventory_loan_draft_lines')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_external_establishment_name_is_saved_with_encrypted_contact_from_window(): void
    {
        [$company, $hall, , $manager] = $this->fixture();
        config()->set('inventory_controls.catalog_enabled', true);
        config()->set('inventory_controls.loan_drafts_enabled', true);
        $id = (string) \Illuminate\Support\Str::uuid();
        app(\App\Services\HallInventoryCatalogService::class)->create(
            $manager, $company->id, $hall->id, $id,
            'Serving Jugs', 'Serving', 'piece', 30, 'Outside borrowing catalog');
        Livewire::test(HallInventory::class)
            ->set('loanDirection', 'external_in')
            ->set('loanOutsideName', 'Outside Marriage Hall')
            ->set('loanOutsideContact', 'External Manager')
            ->set('loanOutsidePhone', '0300-444-4444')
            ->set('loanReturnDate', '2027-06-01')
            ->set('loanPurpose', 'Borrow jugs for weekend event')
            ->call('addLoanRow')
            ->set('loanRows.0.itemId', $id)
            ->set('loanRows.0.quantity', '20')
            ->call('submitLoanDraft')->assertHasNoErrors()
            ->assertSee('Multi-item loan request saved');
        $partner = DB::table('hall_inventory_external_partners')->first();
        $this->assertSame('Outside Marriage Hall', $partner->name);
        $this->assertStringNotContainsString('0300-444-4444', $partner->phone_ciphertext);
    }

    public function test_manager_creates_new_catalog_item_directly_in_borrowing_row(): void
    {
        [$company, $hall, $other, $manager] = $this->fixture();
        config()->set('inventory_controls.catalog_enabled', true);
        config()->set('inventory_controls.loan_drafts_enabled', true);
        $page = Livewire::test(HallInventory::class)->call('addLoanRow');
        $rows = $page->get('loanRows');
        $this->assertCount(1, $rows);
        $page->call('openLoanInlineItem', $rows[0]['key'])
            ->set('loanInlineName', 'Dessert Spoons')
            ->set('loanInlineCategory', 'Cutlery')
            ->set('loanInlineUnit', 'piece')
            ->set('loanInlineMinimumQuantity', '250')
            ->call('saveLoanInlineItem')->assertHasNoErrors()->assertSee('Dessert Spoons');
        $itemId = DB::table('hall_inventory_items')->where('hall_id', $hall->id)
            ->where('name', 'Dessert Spoons')->value('id');
        $this->assertNotNull($itemId);
        $this->assertSame($itemId, $page->get('loanRows')[0]['itemId']);
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('hall_inventory_loan_drafts')->count());
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
