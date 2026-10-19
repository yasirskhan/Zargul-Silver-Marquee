<?php
namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryPurchaseRequestService;
use App\Services\HallInventoryPurchaseReceivingService;
use App\Services\HallInventoryPurchaseReceivingReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class HallInventoryPurchasePostingLivewireTest extends TestCase {
    use RefreshDatabase;
    public function test_authenticated_three_actor_purchase_uses_private_files_and_posts_once():void {
        Storage::fake('local');
        $c=Company::create(['name'=>'Purchase UI Business','slug'=>'purchase-ui-business']);
        $hall=Hall::create(['company_id'=>$c->id,'name'=>'Purchase UI Hall',
            'slug'=>'purchase-ui-hall','capacity'=>200]);
        $users=[];
        foreach(['Requester','Receiver','Reviewer'] as $name) {
            $u=User::create(['name'=>$name,
                'email'=>strtolower($name).'@purchase-ui-workflow.test','password'=>'test-only']);
            $u->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$c->id,'user_id'=>$u->id,'role'=>'owner']);
            $users[$name]=$u;
        }
        config()->set('inventory_controls.catalog_enabled',true);
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        config()->set('inventory_controls.purchase_evidence_upload_enabled',true);
        $buyer=$users['Requester'];$receiver=$users['Receiver'];$reviewer=$users['Reviewer'];
        $this->be($buyer);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($buyer,$c->id,$hall->id,
            $item,'Event plates','Crockery','piece',5,'Purchase new plates');
        $purchase=(string)Str::uuid();
        app(HallInventoryPurchaseRequestService::class)->request(
            $buyer,$c->id,$hall->id,$item,$purchase,10,'Plate Seller','INV-PLATE-10',
            'unverified-reference','New plates delivered');
        Livewire::test(HallInventory::class)
            ->assertSee('Attach original evidence privately')
            ->set('purchaseUploadPurchaseId',$purchase)
            ->set('purchaseUploadKind','supplier_document')
            ->set('purchaseUploadFile',UploadedFile::fake()->createWithContent(
                'invoice.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"))
            ->call('uploadPurchaseEvidence')->assertHasNoErrors()
            ->assertSee('Private evidence saved');
        $this->be($receiver);
        $receipt=(string)Str::uuid();
        app(HallInventoryPurchaseReceivingService::class)->record(
            $receiver,$c->id,$hall->id,$purchase,$receipt,9,
            'receiving-photo-reference','Nine plates counted good');
        Livewire::test(HallInventory::class)
            ->set('purchaseUploadPurchaseId',$purchase)
            ->set('purchaseUploadKind','receiving_photo')
            ->set('purchaseUploadFile',UploadedFile::fake()->createWithContent(
                'photo.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLJ5QAAAABJRU5ErkJggg==')))
            ->call('uploadPurchaseEvidence')->assertHasNoErrors();
        $this->be($reviewer);
        app(HallInventoryPurchaseReceivingReviewService::class)->attest(
            $reviewer,$c->id,$hall->id,$receipt,(string)Str::uuid(),
            'Physical count checked independently');
        $page=Livewire::test(HallInventory::class)
            ->assertSee('INDEPENDENT REVIEW RECORDED / NOT STOCK POSTED')
            ->assertDontSee('Accept purchased stock (no financial entry)');
        config()->set('inventory_controls.purchase_stock_posting_enabled',true);
        Livewire::test(HallInventory::class)
            ->assertSee('Accept purchased stock (no financial entry)')
            ->call('acceptPurchasedStock',$purchase,0)
            ->assertHasNoErrors()
            ->assertSee('INDEPENDENTLY ACCEPTED / OWNED STOCK POSTED / NO FINANCIAL ENTRY')
            ->assertSee('Owned inventory posted: YES. Financially posted: NO.');
        $this->assertSame(9,(int)DB::table('hall_inventory_positions')->value('owned_usable'));
        $this->assertSame(1,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->be($buyer);
        Livewire::test(HallInventory::class)
            ->assertSee('Owned inventory posted: YES. Financially posted: NO.')
            ->assertDontSee('Accept purchased stock (no financial entry)');
    }
}
