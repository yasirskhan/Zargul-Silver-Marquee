<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryPurchaseRequestService;
use App\Services\HallInventoryPurchaseEvidenceFileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryPurchaseEvidenceFileServiceTest extends TestCase {
    use RefreshDatabase;

    public function test_private_custody_is_hall_scoped_flagged_immutable_and_does_not_mint_stock_or_money():void {
        Storage::fake('local');
        $company=Company::create(['name'=>'Evidence Company','slug'=>'evidence-company']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Evidence Hall','slug'=>'evidence-hall','capacity'=>100]);
        $other=Hall::create(['company_id'=>$company->id,'name'=>'Other Evidence Hall','slug'=>'other-evidence-hall','capacity'=>100]);
        $requester=User::create(['name'=>'Evidence Requester','email'=>'evidence-requester@example.test','password'=>'test-only']);
        $receiver=User::create(['name'=>'Evidence Receiver','email'=>'evidence-receiver@example.test','password'=>'test-only']);
        foreach ([$requester,$receiver] as $person) {
            $person->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$person->id,'role'=>'owner']);
        }
        $this->be($requester);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($requester,$company->id,$hall->id,
            $item,'Evidence chairs','Furniture','piece',2,'New evidence catalog');
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        $purchase=(string)Str::uuid();
        app(HallInventoryPurchaseRequestService::class)->request($requester,$company->id,$hall->id,
            $item,$purchase,8,'Evidence Vendor','INV-EVD-8',
            'supplier-doc-claim-unverified','Supplier delivery evidence');
        $service=app(HallInventoryPurchaseEvidenceFileService::class);
        $id=(string)Str::uuid();
        $file=fn()=>UploadedFile::fake()->createWithContent('invoice.pdf','%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF');
        config()->set('inventory_controls.purchase_evidence_upload_enabled',false);
        try {
            $service->attach($requester,$company->id,$hall->id,$purchase,$id,'supplier_document',$file());
            $this->fail('Disabled evidence upload accepted.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.purchase_evidence_upload_enabled',true);
        try {
            $service->attach($requester,$company->id,$other->id,$purchase,$id,'supplier_document',$file());
            $this->fail('Foreign hall evidence accepted.');
        }catch(ConflictHttpException){}
        $this->be($receiver);
        try {
            $service->attach($receiver,$company->id,$hall->id,$purchase,$id,'supplier_document',$file());
            $this->fail('Receiver forged request document.');
        }catch(AuthorizationException){}
        $this->be($requester);
        $this->assertSame($id,$service->attach($requester,$company->id,$hall->id,
            $purchase,$id,'supplier_document',$file()));
        $this->assertSame($id,$service->attach($requester,$company->id,$hall->id,
            $purchase,$id,'supplier_document',$file()));
        $row=DB::table('hall_inventory_purchase_evidence_files')->first();
        $this->assertSame('supplier_document',$row->evidence_kind);
        $this->assertSame(64,strlen($row->sha256));
        $this->assertTrue(Storage::disk('local')->exists($row->storage_path));
        $this->assertSame(1,DB::table('hall_inventory_purchase_evidence_files')->count());
        $this->assertSame(1,DB::table('audit_events')->where('action','hall_inventory.purchase_evidence_uploaded')->count());
        try {
            $service->attach($requester,$company->id,$hall->id,$purchase,$id,'supplier_document',
                UploadedFile::fake()->createWithContent('different.pdf','%PDF-1.5\nchanged'));
            $this->fail('Different bytes reused evidence key.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        try {
            DB::transaction(fn()=>DB::table('hall_inventory_purchase_evidence_files')
                ->where('id',$id)->update(['sha256'=>str_repeat('f',64)]));
            $this->fail('Evidence metadata was mutable.');
        }catch(\Illuminate\Database\QueryException){}
    }
}
