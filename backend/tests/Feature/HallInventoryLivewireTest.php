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
            ->assertSee('Borrowing requests and source dispatch status')
            ->assertSee('Hall B evening wedding request')
            ->assertSee('50 piece')
            ->assertSee('100 piece');
        $this->assertSame(1, DB::table('hall_inventory_loan_drafts')->count());
        $this->assertSame(2, DB::table('hall_inventory_loan_draft_lines')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_destination_manager_sees_incoming_draft_without_lending_hall_access_or_stock_movement(): void
    {
        [$company,$source,$destination,$manager] = $this->fixture();
        config()->set('inventory_controls.catalog_enabled', true);
        config()->set('inventory_controls.loan_drafts_enabled', true);
        $itemId=(string) \Illuminate\Support\Str::uuid();
        app(\App\Services\HallInventoryCatalogService::class)->create(
            $manager,$company->id,$source->id,$itemId,
            'Incoming chairs','Furniture','piece',20,'Source hall catalog');
        $loan=(string) \Illuminate\Support\Str::uuid();
        app(\App\Services\HallInventoryLoanDraftService::class)->create(
            $manager,$company->id,$loan,'internal',$source->id,$destination->id,
            [['itemId'=>$itemId,'quantity'=>12]],'2027-05-20','Incoming chairs for destination');
        $receiver=User::create(['name'=>'Destination Only Manager',
            'email'=>'destination-manager@example.test','password'=>'test-only']);
        $receiver->markEmailAsVerified();
        $membership=CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$receiver->id,'role'=>'manager',
        ]);
        HallAssignment::create(['company_id'=>$company->id,
            'membership_id'=>$membership->id,'hall_id'=>$destination->id]);
        $this->be($receiver);
        Livewire::test(HallInventory::class)
            ->assertSee('Marquee B')
            ->assertSet('hallId', $destination->id)
            ->assertSee('Incoming chairs for destination')
            ->assertSee('INCOMING REQUEST')
            ->assertSee('DRAFT / NOT DISPATCHED');
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
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

    public function test_opening_count_review_is_hall_scoped_and_reflects_independent_acceptance():void
    {
        [$company,$hall,$other,$manager,$membership]=$this->fixture();
        config()->set('inventory_controls.catalog_enabled',true);
        config()->set('inventory_controls.stocktakes_enabled',true);
        config()->set('inventory_controls.opening_stock_posting_enabled',true);
        $item=(string)\Illuminate\Support\Str::uuid();
        app(\App\Services\HallInventoryCatalogService::class)->create(
            $manager,$company->id,$hall->id,$item,
            'Wedding Chairs','Furniture','piece',30,
            'Approved catalogue entry'
        );
        $take=(string)\Illuminate\Support\Str::uuid();
        app(\App\Services\HallInventoryStocktakeService::class)->submit(
            $manager,$company->id,$hall->id,$take,'opening',[
                ['itemId'=>$item,'ownership'=>'owned',
                 'usableCount'=>50,'damagedCount'=>0],
            ],'Witnessed opening chairs count'
        );
        $request=(string)\Illuminate\Support\Str::uuid();
        Livewire::test(HallInventory::class)
            ->set('openingItemId',$item)
            ->set('openingStocktakeId',$take)
            ->set('openingQuantity','50')
            ->set('openingReason','Chairs present at opening')
            ->call('submitOpeningRequest')->assertHasNoErrors()
            ->assertSee('Opening evidence request saved:');
        $request=DB::table('hall_inventory_opening_requests')->value('id');
        Livewire::test(HallInventory::class)
            ->assertSee('Opening stock: independent review')
            ->assertSee('Wedding Chairs')
            ->assertSee('PENDING INDEPENDENT REVIEW')
            ->assertSee('Financial posting: NONE')
            ->assertDontSee('ACCEPTED / BOOKED');
        $owner=User::create(['name'=>'Reviewing Owner',
            'email'=>'opening-ui-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        $this->be($owner);
        Livewire::test(HallInventory::class)
            ->assertSee('Accept verified opening stock')
            ->call('acceptOpeningRequest',$request)->assertHasNoErrors()
            ->assertSee('ACCEPTED / BOOKED');
        $this->be($manager);
        Livewire::test(HallInventory::class)
            ->assertSee('ACCEPTED / BOOKED')
            ->assertSee('Immutable movement:')
            ->assertSee('50 piece')
            ->assertSee('Financial posting: NONE');
        $this->assertSame(0,DB::table('finance_journals')->count());

        $page=Livewire::test(HallInventory::class);
        DB::table('company_memberships')->where('id',$membership->id)
            ->update(['is_active'=>false]);
        $page->call('reloadOpeningHistory')->assertForbidden();
    }


    public function test_purchase_claim_ui_is_default_off_and_manager_can_submit_without_stock_posting():void
    {
        [$company,$hall,$other,$manager,$membership]=$this->fixture();
        config()->set('inventory_controls.catalog_enabled',true);
        $item=(string)\Illuminate\Support\Str::uuid();
        app(\App\Services\HallInventoryCatalogService::class)->create(
            $manager,$company->id,$hall->id,$item,'Purchase Dining Tables',
            'Furniture','piece',5,'Request catalog addition');
        Livewire::test(HallInventory::class)->assertDontSee('Purchased stock: receiving requested');
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        $page=Livewire::test(HallInventory::class)
            ->assertSee('Purchased stock: receiving requested')
            ->set('purchaseItemId',$item)
            ->set('purchaseQuantity','12')
            ->set('purchaseVendor','Dining Table Supplier')
            ->set('purchaseVendorReference','INV-325')
            ->set('purchaseEvidence','sha256:dining-tables-fixture')
            ->set('purchaseReason','New dining table delivery review')
            ->call('submitPurchaseRequest')->assertHasNoErrors()
            ->assertSee('Purchase claim saved:')
            ->assertSee('UNVERIFIED CLAIM / AWAITING INDEPENDENT RECEIVING')
            ->assertSee('Owned inventory posted: NO');
        $this->assertSame(1,DB::table('hall_inventory_purchase_requests')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        DB::table('company_memberships')->where('id',$membership->id)
            ->update(['is_active'=>false]);
        $page->call('reloadPurchaseHistory')->assertForbidden();
    }


    public function test_independent_purchase_receiving_and_third_actor_review_are_available_in_hall_ui_without_posting():void
    {
        [$company,$hall,$other,$manager,$membership]=$this->fixture();
        config()->set('inventory_controls.catalog_enabled',true);
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        $item=(string)\Illuminate\Support\Str::uuid();
        app(\App\Services\HallInventoryCatalogService::class)->create(
            $manager,$company->id,$hall->id,$item,'Purchase event tables',
            'Furniture','piece',5,'New event tables');
        $request=(string)\Illuminate\Support\Str::uuid();
        app(\App\Services\HallInventoryPurchaseRequestService::class)->request(
            $manager,$company->id,$hall->id,$item,$request,9,
            'Table Seller','INV-TABLES-9','vendor-proof-reference',
            'Physical receipt requested');
        $receiver=User::create(['name'=>'Purchase Receiver',
            'email'=>'purchase-ui-receiver@example.test','password'=>'test-only']);
        $receiver->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$receiver->id,'role'=>'owner']);
        $reviewer=User::create(['name'=>'Purchase Reviewer',
            'email'=>'purchase-ui-reviewer@example.test','password'=>'test-only']);
        $reviewer->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$reviewer->id,'role'=>'admin']);
        $this->be($receiver);
        Livewire::test(HallInventory::class)
            ->assertSee('Record independently counted delivery')
            ->set('receivingPurchaseId',$request)
            ->set('receivingQuantity','9')
            ->set('receivingEvidenceRef','physical-proof-9-tables')
            ->set('receivingCondition','All nine tables counted in hall')
            ->call('recordPhysicalPurchaseReceiving')->assertHasNoErrors()
            ->assertSee('PHYSICAL RECEIVING ASSERTED / AWAITING INDEPENDENT REVIEW');
        $receipt=DB::table('hall_inventory_purchase_receiving_evidence')
            ->where('purchase_request_id',$request)->value('id');
        $this->assertNotNull($receipt);
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->be($reviewer);
        Livewire::test(HallInventory::class)
            ->assertSee('Owner/admin independent receiving review')
            ->set('reviewReceiptId',$receipt)
            ->set('reviewReceiptNotes','Independent reviewer confirms recorded count')
            ->call('independentlyReviewPurchaseReceiving')->assertHasNoErrors()
            ->assertSee('INDEPENDENT REVIEW RECORDED / NOT STOCK POSTED');
        $this->assertSame(1,DB::table('hall_inventory_purchase_receiving_reviews')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->be($manager);
        Livewire::test(HallInventory::class)
            ->assertSee('INDEPENDENT REVIEW RECORDED / NOT STOCK POSTED')
            ->assertSee('Owned inventory posted: NO. Financially posted: NO.');
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
    public function test_destination_manager_confirms_real_internal_receipt_through_livewire_without_becoming_owner():void
    {
        [$company,$source,$destination,$sourceManager]=$this->fixture();
        config()->set('inventory_controls.catalog_enabled',true);
        config()->set('inventory_controls.loan_drafts_enabled',true);
        config()->set('inventory_controls.loan_dispatch_enabled',true);
        config()->set('inventory_controls.loan_receipt_enabled',true);
        \\Illuminate\\Support\\Facades\\Storage::fake('local');
        $owner=User::create(['name'=>'Independent Loan Dispatcher',
            'email'=>'livewire-loan-dispatcher@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        $receiver=User::create(['name'=>'Independent Destination Manager',
            'email'=>'livewire-receiving-manager@example.test','password'=>'test-only']);
        $receiver->markEmailAsVerified();
        $membership=CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$receiver->id,'role'=>'manager']);
        HallAssignment::create(['company_id'=>$company->id,
            'membership_id'=>$membership->id,'hall_id'=>$destination->id]);
        $sourceItem=(string)\\Illuminate\\Support\\Str::uuid();
        $destItem=(string)\\Illuminate\\Support\\Str::uuid();
        $catalog=app(\\App\\Services\\HallInventoryCatalogService::class);
        $catalog->create($sourceManager,$company->id,$source->id,$sourceItem,
            'Loan chair covers','Furniture','piece',10,'Origin hall chair covers');
        $this->be($owner);
        $catalog->create($owner,$company->id,$destination->id,$destItem,
            'Loan chair covers','Furniture','piece',10,'Destination borrowed catalog');
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)\\Illuminate\\Support\\Str::uuid(),'company_id'=>$company->id,
            'hall_id'=>$source->id,'item_id'=>$sourceItem,
            'owned_usable'=>20,'revision'=>0,'created_at'=>now(),'updated_at'=>now()
        ]);
        $this->be($sourceManager);
        $loanId=(string)\\Illuminate\\Support\\Str::uuid();
        app(\\App\\Services\\HallInventoryLoanDraftService::class)->create(
            $sourceManager,$company->id,$loanId,'internal',$source->id,$destination->id,
            [['itemId'=>$sourceItem,'quantity'=>6]],'2027-09-30',
            'Real independent destination receipt panel');
        $pdf=fn()=>\\Illuminate\\Http\\UploadedFile::fake()->createWithContent(
            'handover.pdf',"%PDF-1.4\\n1 0 obj\\n<<>>\\nendobj\\n%%EOF");
        $this->be($owner);
        app(\\App\\Services\\HallInventoryInternalLoanDispatchService::class)->dispatch(
            $owner,$company->id,$loanId,(string)\\Illuminate\\Support\\Str::uuid(),
            [$sourceItem=>0],$pdf());
        $this->be($receiver);
        Livewire::test(HallInventory::class)
            ->assertSet('hallId',$destination->id)
            ->assertSee('SOURCE DISPATCHED / DESTINATION RECEIPT PENDING')
            ->call('startLoanReceipt',$loanId)
            ->assertHasNoErrors()
            ->assertSee('Confirm receiving-hall physical custody')
            ->set('loanReceiptMapping.'.$sourceItem,$destItem)
            ->set('loanReceiptFile',$pdf())
            ->call('submitLoanReceipt')->assertHasNoErrors()
            ->assertSee('DESTINATION RECEIVED / RETURNS PENDING');
        $this->assertSame(6,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$destination->id)->where('item_id',$destItem)->value('borrowed_usable'));
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$destination->id)->where('item_id',$destItem)->value('owned_usable'));
        $this->assertSame(6,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$source->id)->where('item_id',$sourceItem)->value('owned_on_loan'));
        $this->assertSame(1,DB::table('hall_inventory_internal_loan_receipts')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
    }

}
