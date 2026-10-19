<?php
namespace Tests\Feature;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryPurchaseRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
class HallInventoryPurchaseRequestServiceTest extends TestCase {
    use RefreshDatabase;
    public function test_purchase_claim_is_gated_immutable_idempotent_and_not_inventory_or_money():void {
        $c=Company::create(['name'=>'Purchasing Company','slug'=>'purchasing-company']);
        $h=Hall::create(['company_id'=>$c->id,'name'=>'Purchase Hall','slug'=>'purchase-hall','capacity'=>200]);
        $u=User::create(['name'=>'Purchasing Owner','email'=>'purchase-owner@example.test','password'=>'test-only']);
        $u->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$c->id,'user_id'=>$u->id,'role'=>'owner']);
        $this->be($u);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($u,$c->id,$h->id,
            $item,'Chair purchase','Furniture','piece',2,'Purchase catalog item');
        $s=app(HallInventoryPurchaseRequestService::class);
        $id=(string)Str::uuid();
        $call=fn(int $q)=>$s->request($u,$c->id,$h->id,$item,$id,$q,
            'Chair Vendor','INV-2026-100','receipt-sha256:fixture','Pending receiving inspection');
        try { $call(20); $this->fail('Gate OFF must deny.'); }
        catch(ConflictHttpException) {}
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        $this->assertSame($id,$call(20));
        $this->assertSame($id,$call(20));
        try { $call(21); $this->fail('Different-content retry accepted.'); }
        catch(ConflictHttpException) {}
        $this->assertSame(1,DB::table('hall_inventory_purchase_requests')->count());
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.purchase_requested')->count());
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        try {
            // RefreshDatabase wraps each test in a PostgreSQL transaction.
            // Isolate the intentional trigger failure in a nested savepoint so
            // later assertions do not see an aborted outer transaction.
            DB::transaction(function()use($id):void {
                DB::table('hall_inventory_purchase_requests')
                    ->where('id',$id)->update(['quantity'=>99]);
            });
            $this->fail('Immutable evidence altered.');
        }catch(\Illuminate\Database\QueryException){}
        $other=Hall::create(['company_id'=>$c->id,'name'=>'Other Hall',
            'slug'=>'other-purchase-hall','capacity'=>200]);
        try {
            $s->request($u,$c->id,$other->id,$item,(string)Str::uuid(),20,
                'Chair Vendor','INV-2026-100','receipt-sha256:fixture','Foreign hall item');
            $this->fail('Foreign hall accepted.');
        }catch(AuthorizationException){}
        config()->set('inventory_controls.purchase_receipts_enabled',false);
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
    }
}