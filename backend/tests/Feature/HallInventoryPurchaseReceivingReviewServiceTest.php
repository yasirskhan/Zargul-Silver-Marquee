<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryPurchaseRequestService;
use App\Services\HallInventoryPurchaseReceivingService;
use App\Services\HallInventoryPurchaseReceivingReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryPurchaseReceivingReviewServiceTest extends TestCase {
    use RefreshDatabase;

    public function test_independent_third_actor_review_is_immutable_and_never_posts_stock_or_money():void {
        $c=Company::create(['name'=>'Review Company','slug'=>'receiving-review-company']);
        $h=Hall::create(['company_id'=>$c->id,'name'=>'Review Hall','slug'=>'receiving-review-hall','capacity'=>100]);
        $people=[];
        foreach(['Requester','Receiver','Reviewer'] as $name) {
            $person=User::create(['name'=>$name,'email'=>strtolower($name).'@receiving-review.test','password'=>'test-only']);
            $person->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$c->id,'user_id'=>$person->id,'role'=>'owner']);
            $people[$name]=$person;
        }
        $requester=$people['Requester']; $receiver=$people['Receiver']; $reviewer=$people['Reviewer'];
        $this->be($requester);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($requester,$c->id,$h->id,
            $item,'Purchase plates','Furniture','piece',5,'New physical plates');
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        $request=(string)Str::uuid();
        app(HallInventoryPurchaseRequestService::class)->request($requester,$c->id,$h->id,
            $item,$request,12,'Plates Vendor','INV-PLATES-12',
            'supplier-document-id-example','Plates delivery evidence');
        $this->be($receiver);
        $receipt=(string)Str::uuid();
        app(HallInventoryPurchaseReceivingService::class)->record($receiver,$c->id,$h->id,
            $request,$receipt,12,'physical-receiving-reference-example',
            'Received cartons inspected');
        $svc=app(HallInventoryPurchaseReceivingReviewService::class);
        $review=(string)Str::uuid();
        $notes='Separate physical receiving assertion reviewed';
        foreach([$receiver,$requester] as $actor) {
            $this->be($actor);
            try {
                $svc->attest($actor,$c->id,$h->id,$receipt,$review,$notes);
                $this->fail('Non-independent review accepted.');
            } catch(AuthorizationException) {}
        }
        $this->be($reviewer);
        config()->set('inventory_controls.purchase_receipts_enabled',false);
        try {
            $svc->attest($reviewer,$c->id,$h->id,$receipt,$review,$notes);
            $this->fail('Release switch off but review accepted.');
        } catch(ConflictHttpException) {}
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        $this->assertSame($review,$svc->attest($reviewer,$c->id,$h->id,$receipt,$review,$notes));
        $this->assertSame($review,$svc->attest($reviewer,$c->id,$h->id,$receipt,$review,$notes));
        try {
            $svc->attest($reviewer,$c->id,$h->id,$receipt,$review,'Different independent review notes');
            $this->fail('Conflicting review replay accepted.');
        } catch(ConflictHttpException) {}
        $this->assertSame(1,DB::table('hall_inventory_purchase_receiving_reviews')->count());
        $this->assertSame(1,DB::table('audit_events')->where('action','hall_inventory.purchase_receiving_reviewed')->count());
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        try {
            DB::transaction(function()use($review):void {
                DB::table('hall_inventory_purchase_receiving_reviews')->where('id',$review)
                    ->update(['review_notes'=>'tampered']);
            });
            $this->fail('Immutable review was changed.');
        } catch(\Illuminate\Database\QueryException) {}
    }
}
