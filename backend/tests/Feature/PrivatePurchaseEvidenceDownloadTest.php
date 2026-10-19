<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryPurchaseRequestService;
use App\Services\HallInventoryPurchaseEvidenceFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivatePurchaseEvidenceDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_current_owner_admin_gets_hash_verified_private_attachment_without_financial_effect():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Private Evidence Company','slug'=>'private-evidence-company']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Private Hall',
            'slug'=>'private-evidence-hall','capacity'=>130]);
        $other=Hall::create(['company_id'=>$company->id,'name'=>'Other Hall',
            'slug'=>'other-private-evidence-hall','capacity'=>130]);
        $owner=User::create(['name'=>'Private Owner','email'=>'private-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $outsider=User::create(['name'=>'Private Outsider','email'=>'private-outsider@example.test','password'=>'test-only']);
        $outsider->markEmailAsVerified();
        $this->be($owner);
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        config()->set('inventory_controls.purchase_evidence_upload_enabled',true);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($owner,$company->id,$hall->id,
            $item,'Private evidence chairs','Furniture','piece',1,'Evidence test item');
        $purchase=(string)Str::uuid();
        app(HallInventoryPurchaseRequestService::class)->request($owner,$company->id,$hall->id,
            $item,$purchase,3,'Supplier','S-0001','supplier-reference-pending',
            'Awaiting private supplier document');
        $fileId=(string)Str::uuid();
        $bytes="%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        app(HallInventoryPurchaseEvidenceFileService::class)->attach(
            $owner,$company->id,$hall->id,$purchase,$fileId,'supplier_document',
            UploadedFile::fake()->createWithContent('supplier.pdf',$bytes));
        $uri=route('inventory.purchase-evidence.download',[
            'companyId'=>$company->id,'hallId'=>$hall->id,
            'purchaseId'=>$purchase,'fileId'=>$fileId
        ],false);
        $response=$this->get($uri);
        $response->assertOk()
            ->assertHeader('Content-Type','application/octet-stream')
            ->assertHeader('X-Content-Type-Options','nosniff');
        $this->assertSame($bytes,$response->getContent());
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.purchase_evidence_downloaded')->count());
        $this->be($outsider);
        $this->get($uri)->assertForbidden();
        $this->be($owner);
        $wrongHall=route('inventory.purchase-evidence.download',[
            'companyId'=>$company->id,'hallId'=>$other->id,
            'purchaseId'=>$purchase,'fileId'=>$fileId
        ],false);
        $this->get($wrongHall)->assertStatus(409);
        config()->set('inventory_controls.purchase_evidence_upload_enabled',false);
        $this->get($uri)->assertStatus(409);
        config()->set('inventory_controls.purchase_evidence_upload_enabled',true);
        $path=DB::table('hall_inventory_purchase_evidence_files')
            ->where('id',$fileId)->value('storage_path');
        Storage::disk('local')->put($path,'tampered private evidence');
        $this->get($uri)->assertStatus(409);
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.purchase_evidence_downloaded')->count());
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
    }
}
