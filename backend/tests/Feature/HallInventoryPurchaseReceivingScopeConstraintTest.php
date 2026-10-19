<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryPurchaseRequestService;
use App\Services\HallInventoryPurchaseReceivingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HallInventoryPurchaseReceivingScopeConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_privileged_sql_cannot_bind_receipt_to_other_hall_or_review_to_other_purchase(): void
    {
        $c=Company::create(['name'=>'Purchase FK Company','slug'=>'purchase-fk-company']);
        $h=Hall::create(['company_id'=>$c->id,'name'=>'Purchase FK Hall','slug'=>'purchase-fk-hall','capacity'=>100]);
        $other=Hall::create(['company_id'=>$c->id,'name'=>'Other FK Hall','slug'=>'other-fk-hall','capacity'=>100]);
        $owner=User::create(['name'=>'FK Owner','email'=>'fk-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        $receiver=User::create(['name'=>'FK Receiver','email'=>'fk-receiver@example.test','password'=>'test-only']);
        $receiver->markEmailAsVerified();
        foreach ([$owner,$receiver] as $actor) {
            CompanyMembership::create(['company_id'=>$c->id,'user_id'=>$actor->id,'role'=>'owner']);
        }
        $this->be($owner);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($owner,$c->id,$h->id,
            $item,'Scoped purchase chairs','Furniture','piece',2,'Scoped purchase item');
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        $request=(string)Str::uuid();
        app(HallInventoryPurchaseRequestService::class)->request($owner,$c->id,$h->id,
            $item,$request,5,'Scoped Supplier','INV-SCOPE-5',
            'supplier-reference-scoped','Scope test delivery pending');
        $receipt=(string)Str::uuid();
        $this->be($receiver);
        app(HallInventoryPurchaseReceivingService::class)->record($receiver,$c->id,$h->id,
            $request,$receipt,5,'receiving-proof-scope-reference','Five units inspected');
        $this->assertSame(1,DB::table('hall_inventory_purchase_receiving_evidence')->count());

        // A second legitimate claim avoids a false positive from the
        // one-receipt-per-request unique key. Only the composite scope FK
        // should reject this otherwise unused request.
        $this->be($owner);
        $secondRequest=(string)Str::uuid();
        app(HallInventoryPurchaseRequestService::class)->request($owner,$c->id,$h->id,
            $item,$secondRequest,5,'Scoped Supplier','INV-SCOPE-6',
            'supplier-reference-scoped-two','Second scoped delivery pending');
        $this->be($receiver);
        try {
            DB::transaction(function()use($c,$other,$item,$secondRequest,$receiver):void {
                DB::table('hall_inventory_purchase_receiving_evidence')->insert([
                    'id'=>(string)Str::uuid(),'purchase_request_id'=>$secondRequest,
                    'company_id'=>$c->id,'hall_id'=>$other->id,'item_id'=>$item,
                    'counted_quantity'=>1,'physical_evidence_reference'=>'foreign-hall-proof',
                    'condition_notes'=>'Foreign hall fake receiving record',
                    'receipt_fingerprint'=>str_repeat('a',64),'received_by'=>$receiver->id
                ]);
            });
            $this->fail('Foreign hall could bind to original purchase.');
        } catch(QueryException) {}
        $this->assertSame(1,DB::table('hall_inventory_purchase_receiving_evidence')->count());

        try {
            DB::transaction(function()use($c,$other,$request,$receipt,$owner):void {
                DB::table('hall_inventory_purchase_receiving_reviews')->insert([
                    'id'=>(string)Str::uuid(),'receiving_evidence_id'=>$receipt,
                    'company_id'=>$c->id,'hall_id'=>$other->id,
                    'purchase_request_id'=>$request,'reviewed_by'=>$owner->id,
                    'review_notes'=>'Foreign hall review must fail',
                    'review_fingerprint'=>str_repeat('b',64)
                ]);
            });
            $this->fail('Foreign hall could attest unrelated physical receiving.');
        } catch(QueryException) {}
        $this->assertSame(0,DB::table('hall_inventory_purchase_receiving_reviews')->count());
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
    }
}
