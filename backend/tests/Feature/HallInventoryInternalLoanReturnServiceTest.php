<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryLoanDraftService;
use App\Services\HallInventoryInternalLoanDispatchService;
use App\Services\HallInventoryInternalLoanReceiptService;
use App\Services\HallInventoryInternalLoanReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryInternalLoanReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_return_batches_preserve_lender_ownership_and_require_source_acknowledgment():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Internal Return Company','slug'=>'internal-return-company']);
        $src=Hall::create(['company_id'=>$company->id,'name'=>'Return Origin','slug'=>'return-origin','capacity'=>100]);
        $dest=Hall::create(['company_id'=>$company->id,'name'=>'Return Destination','slug'=>'return-destination','capacity'=>100]);
        $owner=User::create(['name'=>'Return Draft Owner','email'=>'return-draft-owner@example.test','password'=>'test-only']);
        $dispatcher=User::create(['name'=>'Return Source Dispatcher','email'=>'return-dispatcher@example.test','password'=>'test-only']);
        $receiver=User::create(['name'=>'Return Destination Manager','email'=>'return-receiver@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$dispatcher,'admin'],[$receiver,'manager']] as [$actor,$role]) {
            $actor->markEmailAsVerified();
            $membership=CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$actor->id,'role'=>$role]);
            if($actor->id===$receiver->id)
                HallAssignment::create(['company_id'=>$company->id,'hall_id'=>$dest->id,
                    'membership_id'=>$membership->id]);
        }
        $this->be($owner);
        $catalog=app(HallInventoryCatalogService::class);
        $srcItem=(string)Str::uuid();$dstItem=(string)Str::uuid();
        $catalog->create($owner,$company->id,$src->id,$srcItem,
            'Return serving trays','Furniture','piece',2,'Source return catalog');
        $catalog->create($owner,$company->id,$dest->id,$dstItem,
            'Return serving trays','Furniture','piece',2,'Destination return catalog');
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,
            'hall_id'=>$src->id,'item_id'=>$srcItem,'owned_usable'=>30,
            'revision'=>0,'created_at'=>now(),'updated_at'=>now()
        ]);
        config()->set('inventory_controls.loan_drafts_enabled',true);
        config()->set('inventory_controls.loan_dispatch_enabled',true);
        config()->set('inventory_controls.loan_receipt_enabled',true);
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,$loan,
            'internal',$src->id,$dest->id,[['itemId'=>$srcItem,'quantity'=>12]],
            '2027-12-30','Partial multi-batch return receipt');
        $pdf=fn()=>UploadedFile::fake()->createWithContent('handover.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->be($dispatcher);
        app(HallInventoryInternalLoanDispatchService::class)->dispatch(
            $dispatcher,$company->id,$loan,(string)Str::uuid(),[$srcItem=>0],$pdf());
        $this->be($receiver);
        app(HallInventoryInternalLoanReceiptService::class)->receive(
            $receiver,$company->id,$loan,(string)Str::uuid(),
            [$srcItem=>$dstItem],[$dstItem=>0],$pdf());
        $svc=app(HallInventoryInternalLoanReturnService::class);
        $first=(string)Str::uuid();
        $send=fn(string $id,int $qty,int $revision)=>$svc->send(
            $receiver,$company->id,$loan,$id,[$srcItem=>$qty],
            [$dstItem=>$revision],$pdf());
        try{$send($first,5,1);$this->fail('Return released despite gate OFF.');}
        catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_returns_enabled',true);
        try{$send($first,5,0);$this->fail('Stale destination return revision accepted.');}
        catch(ConflictHttpException){}
        $this->be($owner);
        try{
            $svc->send($owner,$company->id,$loan,$first,
                [$srcItem=>5],[$dstItem=>1],$pdf());
            $this->fail('Originator sent borrower return without independent custody.');
        }catch(AuthorizationException){}
        $this->be($receiver);
        $this->assertSame($first,$send($first,5,1));
        $this->assertSame($first,$send($first,5,1));
        $this->assertSame(7,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->value('borrowed_usable'));
        $this->assertSame(5,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->value('borrowed_transit'));
        $this->assertSame(12,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$src->id)->where('item_id',$srcItem)->value('owned_on_loan'));
        $second=(string)Str::uuid();
        $this->assertSame($second,$send($second,3,2));
        $this->assertSame(4,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->value('borrowed_usable'));
        $this->assertSame(8,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->value('borrowed_transit'));
        try{$send((string)Str::uuid(),5,3);$this->fail('Borrowed return exceeds original 12.');}
        catch(ConflictHttpException){}
        try{$send($first,6,1);$this->fail('Conflicting immutable return batch retry accepted.');}
        catch(ConflictHttpException){}
        $this->assertSame(2,DB::table('hall_inventory_internal_loan_return_batches')->count());
        $this->assertSame(2,DB::table('hall_inventory_internal_loan_return_lines')->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('event_kind','send_borrowed_back')->count());
        $this->assertSame(2,DB::table('audit_events')
            ->where('action','hall_inventory.internal_loan_partial_return_sent')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$src->id)->where('item_id',$srcItem)->value('owned_damaged'));
        // Source ownership stays ON LOAN until independent arrival/condition review.
        $this->assertSame(12,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$src->id)->where('item_id',$srcItem)->value('owned_on_loan'));
    }
}
