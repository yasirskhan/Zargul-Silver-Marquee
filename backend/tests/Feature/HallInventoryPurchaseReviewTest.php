<?php
namespace Tests\Feature;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryPurchaseRequestService;
use App\Services\HallInventoryPurchaseReview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
class HallInventoryPurchaseReviewTest extends TestCase {
    use RefreshDatabase;
    public function test_read_only_review_is_hall_scoped_and_never_calls_claim_accepted():void {
        $company=Company::create(['name'=>'Read Claim Company','slug'=>'read-claim-company']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Read Claim Hall',
            'slug'=>'read-claim-hall','capacity'=>150]);
        $other=Hall::create(['company_id'=>$company->id,'name'=>'Other Claim Hall',
            'slug'=>'other-claim-hall','capacity'=>150]);
        $owner=User::create(['name'=>'Claim Reader','email'=>'claim-reader@example.test',
            'password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $this->be($owner);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($owner,$company->id,$hall->id,
            $item,'Claim chairs','Furniture','piece',10,'Create claim catalog');
        $reader=app(HallInventoryPurchaseReview::class);
        try {
            $reader->recent($owner,$company->id,$hall->id);
            $this->fail('Disabled purchase review should deny.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.purchase_receipts_enabled',true);
        $id=(string)Str::uuid();
        app(HallInventoryPurchaseRequestService::class)->request(
            $owner,$company->id,$hall->id,$item,$id,25,'Vendor X',
            'INV-25','sha256:example-purchase-document','Request new chairs');
        $rows=$reader->recent($owner,$company->id,$hall->id);
        $this->assertCount(1,$rows);
        $this->assertSame($id,$rows[0]['id']);
        $this->assertSame(25,$rows[0]['quantity']);
        $this->assertSame('UNVERIFIED CLAIM / AWAITING INDEPENDENT RECEIVING',$rows[0]['status']);
        $this->assertFalse($rows[0]['ownedStockPosted']);
        $this->assertFalse($rows[0]['financiallyPosted']);
        $this->assertSame([], $reader->recent($owner,$company->id,$other->id));
        $outsider=User::create(['name'=>'Claim Outsider','email'=>'claim-outsider@example.test',
            'password'=>'test-only']);
        $outsider->markEmailAsVerified();
        $this->be($outsider);
        try {
            $reader->recent($outsider,$company->id,$hall->id);
            $this->fail('Unaffiliated claim review permitted.');
        }catch(AuthorizationException){}
    }
}