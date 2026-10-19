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
use App\Services\HallInventoryPurchaseEvidenceFileService;
use App\Services\HallInventoryPurchaseStockAcceptanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryPurchaseStockAcceptanceServiceTest extends TestCase {
    use RefreshDatabase;

    public function test_only_independent_reviewer_can_post_accepted_owned_stock_once_with_exact_revision_and_no_money():void {
        Storage::fake('local');
        $c=Company::create(['name'=>'Stock Posting Co','slug'=>'posting-stock-co']);
        $hall=Hall::create(['company_id'=>$c->id,'name'=>'Stock Posting Hall',
            'slug'=>'stock-posting-hall','capacity'=>150]);
        $people=[];
        foreach(['Purchaser','Receiver','Approver'] as $n) {
            $u=User::create(['name'=>$n,'email'=>strtolower($n).'@stock-posting.test','password'=>'test-only']);
            $u->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$c->id,'user_id'=>$u->id,'role'=>'owner']);
            $people[$n]=$u;
        }
        $buyer=$people['Purchaser'];$receiver=$people['Receiver'];$approver=$people['Approver'];
        $this->be($buyer);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($buyer,$c->id,$hall->id,
            $item,'Stock chairs','Furniture','piece',3,'Purchased physical stock');
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        config()->set('inventory_controls.purchase_evidence_upload_enabled',true);
        $purchase=(string)Str::uuid();
        app(HallInventoryPurchaseRequestService::class)->request($buyer,$c->id,$hall->id,
            $item,$purchase,20,'Stock Supplier','INV-POST-20',
            'purchase-doc-unverified','Chairs awaiting delivery');
        $files=app(HallInventoryPurchaseEvidenceFileService::class);
        $pdf=fn()=>UploadedFile::fake()->createWithContent('supplier.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $files->attach($buyer,$c->id,$hall->id,$purchase,(string)Str::uuid(),'supplier_document',$pdf());
        $this->be($receiver);
        $receipt=(string)Str::uuid();
        app(HallInventoryPurchaseReceivingService::class)->record($receiver,$c->id,$hall->id,
            $purchase,$receipt,18,'delivery-photo-unverified','Eighteen chairs good');
        $files->attach($receiver,$c->id,$hall->id,$purchase,(string)Str::uuid(),'receiving_photo',\n            UploadedFile::fake()->createWithContent('receiving.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLJ5QAAAABJRU5ErkJggg==')));
        $this->be($approver);
        app(HallInventoryPurchaseReceivingReviewService::class)->attest(
            $approver,$c->id,$hall->id,$receipt,(string)Str::uuid(),
            'Independently inspected eighteen chairs');
        $svc=app(HallInventoryPurchaseStockAcceptanceService::class);
        try {
            $svc->accept($approver,$c->id,$hall->id,$purchase,0);
            $this->fail('Posting gate unexpectedly enabled.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        config()->set('inventory_controls.purchase_stock_posting_enabled',true);
        try {
            $svc->accept($approver,$c->id,$hall->id,$purchase,1);
            $this->fail('Stale initial revision accepted.');
        }catch(ConflictHttpException){}
        $this->be($buyer);
        try {
            $svc->accept($buyer,$c->id,$hall->id,$purchase,0);
            $this->fail('Requester self-acceptance permitted.');
        }catch(AuthorizationException){}
        $this->be($approver);
        $movement=$svc->accept($approver,$c->id,$hall->id,$purchase,0);
        $this->assertSame($movement,$svc->accept($approver,$c->id,$hall->id,$purchase,0));
        $position=DB::table('hall_inventory_positions')->first();
        $this->assertSame(18,(int)$position->owned_usable);
        $this->assertSame(0,(int)$position->borrowed_usable);
        $this->assertSame(0,(int)$position->owned_on_loan);
        $this->assertSame(1,(int)$position->revision);
        $this->assertSame(1,DB::table('hall_inventory_purchase_stock_acceptances')->count());
        $this->assertSame(1,DB::table('hall_inventory_movement_events')
            ->where('event_kind','acquire_owned')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->be($receiver);
        try {
            $svc->accept($receiver,$c->id,$hall->id,$purchase,0);
            $this->fail('Different actor replay returned a movement.');
        }catch(ConflictHttpException){}
        try {
            DB::transaction(fn()=>DB::table('hall_inventory_purchase_stock_acceptances')
                ->where('purchase_request_id',$purchase)->delete());
            $this->fail('Immutable purchase acceptance deleted.');
        }catch(\Illuminate\Database\QueryException){}
        $this->assertSame(18,(int)DB::table('hall_inventory_positions')->value('owned_usable'));
    }
}
