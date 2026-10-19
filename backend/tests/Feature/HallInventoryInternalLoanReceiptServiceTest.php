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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryInternalLoanReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_destination_confirmed_two_item_arrival_adds_borrowed_only_with_independent_custody():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Two Hall Custody','slug'=>'two-hall-custody']);
        $src=Hall::create(['company_id'=>$company->id,'name'=>'Loan Origin','slug'=>'loan-origin','capacity'=>100]);
        $dst=Hall::create(['company_id'=>$company->id,'name'=>'Loan Arrival','slug'=>'loan-arrival','capacity'=>100]);
        $drafter=User::create(['name'=>'Draft Owner','email'=>'receipt-drafter@example.test','password'=>'test-only']);
        $sender=User::create(['name'=>'Dispatch Admin','email'=>'receipt-dispatcher@example.test','password'=>'test-only']);
        $receiver=User::create(['name'=>'Receiving Manager','email'=>'receipt-dest-manager@example.test','password'=>'test-only']);
        foreach([[$drafter,'owner'],[$sender,'admin'],[$receiver,'manager']] as [$actor,$role]) {
            $actor->markEmailAsVerified();
            $membership=CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$actor->id,'role'=>$role]);
            if($actor->id===$receiver->id)
                HallAssignment::create(['company_id'=>$company->id,
                    'hall_id'=>$dst->id,'membership_id'=>$membership->id]);
        }
        $this->be($drafter);
        $catalog=app(HallInventoryCatalogService::class);
        $destMap=[];
        $lines=[];
        $sourcePositions=[];
        foreach([['Stacking chairs',14,6],['Serving trays',7,3]] as [$name,$owned,$sent]) {
            $srcItem=(string)Str::uuid();$dstItem=(string)Str::uuid();
            $catalog->create($drafter,$company->id,$src->id,$srcItem,
                $name,'Furniture','piece',2,'Loan origin catalog');
            $catalog->create($drafter,$company->id,$dst->id,$dstItem,
                $name,'Furniture','piece',2,'Loan receiving catalog');
            $destMap[$srcItem]=$dstItem;
            $lines[]=['itemId'=>$srcItem,'quantity'=>$sent];
            $sourcePositions[$srcItem]=['owned'=>$owned,'sent'=>$sent];
            DB::table('hall_inventory_positions')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                'hall_id'=>$src->id,'item_id'=>$srcItem,'owned_usable'=>$owned,
                'revision'=>0,'created_at'=>now(),'updated_at'=>now()
            ]);
        }
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $loanId=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,
            $loanId,'internal',$src->id,$dst->id,$lines,
            '2027-12-31','Physical borrowing for hall event');
        $evidence=fn()=>UploadedFile::fake()->createWithContent('count.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $revisions=array_fill_keys(array_keys($destMap),0);
        $this->be($sender);
        config()->set('inventory_controls.loan_dispatch_enabled',true);
        $dispatchId=(string)Str::uuid();
        app(HallInventoryInternalLoanDispatchService::class)->dispatch(
            $sender,$company->id,$loanId,$dispatchId,$revisions,$evidence());
        $this->assertSame(0,DB::table('hall_inventory_positions')
            ->where('hall_id',$dst->id)->count());
        $this->be($receiver);
        $receipt=(string)Str::uuid();
        $destRevisions=array_fill_keys(array_values($destMap),0);
        $service=app(HallInventoryInternalLoanReceiptService::class);
        $call=fn(array $mapping,array $expected)=>$service->receive(
            $receiver,$company->id,$loanId,$receipt,$mapping,$expected,$evidence());
        try{$call($destMap,$destRevisions);$this->fail('Default-OFF receiving posted borrowed stock.');}
        catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_receipt_enabled',true);
        $stale=$destRevisions;$stale[array_key_first($stale)]=1;
        try{$call($destMap,$stale);$this->fail('Stale destination revision accepted.');}
        catch(ConflictHttpException){}
        $wrong=$destMap;
        $wrong[array_key_first($wrong)]=$wrong[array_key_last($wrong)];
        try{$call($wrong,$destRevisions);$this->fail('Duplicate destination item accepted.');}
        catch(ConflictHttpException){}
        $this->be($sender);
        try{
            $service->receive($sender,$company->id,$loanId,$receipt,
                $destMap,$destRevisions,$evidence());
            $this->fail('Source dispatcher self-received destination stock.');
        }catch(AuthorizationException){}
        $this->be($receiver);
        $handoverPath=DB::table('hall_inventory_loan_dispatches')
            ->where('id',$dispatchId)->value('evidence_storage_path');
        $originalHandover=Storage::disk('local')->get($handoverPath);
        Storage::disk('local')->put($handoverPath,'altered-physical-handover');
        try {
            $call($destMap,$destRevisions);
            $this->fail('Tampered source handover evidence accepted at destination.');
        } catch(ConflictHttpException) {}
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_receipts')->count());
        $this->assertSame(0,DB::table('hall_inventory_positions')
            ->where('hall_id',$dst->id)->count());
        Storage::disk('local')->put($handoverPath,$originalHandover);
        $this->assertSame($receipt,$call($destMap,$destRevisions));
        $this->assertSame($receipt,$call($destMap,$destRevisions));
        $history=app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($receiver,$company->id,$dst->id);
        $this->assertCount(1,$history);
        $this->assertSame('destination_received_return_pending',$history[0]['status']);
        $this->assertTrue($history[0]['sourceDispatchRecorded']);
        $this->assertTrue($history[0]['destinationReceiptConfirmed']);
        $this->assertNotNull($history[0]['receivedAt']);
        $this->assertSame('receiving',$history[0]['viewRole']);
        foreach($destMap as $srcItem=>$dstItem) {
            $borrowed=DB::table('hall_inventory_positions')
                ->where('hall_id',$dst->id)->where('item_id',$dstItem)->first();
            $this->assertNotNull($borrowed);
            $this->assertSame($sourcePositions[$srcItem]['sent'],(int)$borrowed->borrowed_usable);
            $this->assertSame(0,(int)$borrowed->owned_usable);
            $this->assertSame(1,(int)$borrowed->revision);
            $source=DB::table('hall_inventory_positions')
                ->where('hall_id',$src->id)->where('item_id',$srcItem)->first();
            $this->assertSame($sourcePositions[$srcItem]['sent'],(int)$source->owned_on_loan);
            $this->assertSame($sourcePositions[$srcItem]['owned']-$sourcePositions[$srcItem]['sent'],
                (int)$source->owned_usable);
        }
        $this->assertSame(1,DB::table('hall_inventory_internal_loan_receipts')->count());
        $this->assertSame(2,DB::table('hall_inventory_internal_loan_receipt_lines')->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('event_kind','accept_borrowed')->count());
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.internal_loan_destination_received')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->be($drafter);
        try{$service->receive($drafter,$company->id,$loanId,(string)Str::uuid(),
            $destMap,$destRevisions,$evidence());$this->fail('Draft actor forged destination receipt.');}
        catch(AuthorizationException){}
        $this->be($receiver);
        $conflicting=$destMap;
        [$a,$b]=array_values($destMap);
        $keys=array_keys($conflicting);
        $conflicting[$keys[0]]=$b;$conflicting[$keys[1]]=$a;
        try{$call($conflicting,$destRevisions);$this->fail('Mapping replay changed.');}
        catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('finance_journals')->count());
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$receiver->id)->update(['is_active'=>false]);
        try {
            $call($destMap,$destRevisions);
            $this->fail('Revoked receiving manager replayed a receipt.');
        } catch(AuthorizationException) {}
        $this->assertSame(1,DB::table('hall_inventory_internal_loan_receipts')->count());
    }
}
