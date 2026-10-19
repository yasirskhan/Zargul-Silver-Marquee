<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryPurchaseRequestService;
use App\Services\HallInventoryPurchaseReceivingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryPurchaseReceivingServiceTest extends TestCase {
    use RefreshDatabase;
    public function test_independent_physical_receiving_is_idempotent_gated_and_cannot_post_stock_or_money():void {
        $company=Company::create(['name'=>'Receiving Company','slug'=>'receiving-company']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Receiving Hall','slug'=>'receiving-hall','capacity'=>200]);
        $requester=User::create(['name'=>'Purchase Requester','email'=>'purchase-requester@example.test','password'=>'test-only']);
        $receiver=User::create(['name'=>'Independent Receiver','email'=>'independent-receiver@example.test','password'=>'test-only']);
        foreach([$requester,$receiver] as $person) {
            $person->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$person->id,'role'=>'owner']);
        }
        $this->be($requester);
        $itemId=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($requester,$company->id,$hall->id,
            $itemId,'Receiving chairs','Furniture','piece',2,'Physical receipt catalog');
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        $requestId=(string)Str::uuid();
        app(HallInventoryPurchaseRequestService::class)->request(
            $requester,$company->id,$hall->id,$itemId,$requestId,20,
            'Supplier chairs','INV-2026-20','receipt-sha256:example',
            'Physical delivery pending');
        $service=app(HallInventoryPurchaseReceivingService::class);
        $receiptId=(string)Str::uuid();
        $record=fn(int $qty)=>$service->record($receiver,$company->id,$hall->id,
            $requestId,$receiptId,$qty,'receiving-photo-sha256:example',
            'All cartons physically counted');
        try {
            $service->record($requester,$company->id,$hall->id,$requestId,
                $receiptId,20,'receiving-photo-sha256:example','Self witnessed');
            $this->fail('Self receiving permitted.');
        } catch(AuthorizationException) {}
        $this->be($receiver);
        config()->set('inventory_controls.purchase_receipts_enabled',false);
        try { $record(20); $this->fail('Disabled receipt accepted.'); }
        catch(ConflictHttpException) {}
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        try { $record(21); $this->fail('Over-receipt accepted.'); }
        catch(ConflictHttpException) {}
        $this->assertSame($receiptId,$record(20));
        $this->assertSame($receiptId,$record(20));
        try { $record(19); $this->fail('Conflicting replay accepted.'); }
        catch(ConflictHttpException) {}
        $this->assertSame(1,DB::table('hall_inventory_purchase_receiving_evidence')->count());
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.purchase_receiving_asserted')->count());
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        try {
            DB::transaction(function()use($receiptId):void {
                DB::table('hall_inventory_purchase_receiving_evidence')->where('id',$receiptId)
                    ->update(['counted_quantity'=>2]);
            });
            $this->fail('Immutable receiving changed.');
        } catch(\Illuminate\Database\QueryException) {}
    }
}
