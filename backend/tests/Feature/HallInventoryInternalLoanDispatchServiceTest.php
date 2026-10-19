<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryLoanDraftService;
use App\Services\HallInventoryInternalLoanDispatchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryInternalLoanDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_item_dispatch_is_independent_gated_atomic_and_does_not_receive_or_pay():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Internal Custody Company','slug'=>'internal-custody-company']);
        $source=Hall::create(['company_id'=>$company->id,
            'name'=>'Dispatch Hall','slug'=>'dispatch-hall','capacity'=>150]);
        $dest=Hall::create(['company_id'=>$company->id,
            'name'=>'Destination Hall','slug'=>'destination-hall','capacity'=>200]);
        $owner=User::create(['name'=>'Loan Drafter',
            'email'=>'loan-drafter@example.test','password'=>'test-only']);
        $admin=User::create(['name'=>'Loan Dispatcher',
            'email'=>'loan-dispatcher@example.test','password'=>'test-only']);
        foreach ([[$owner,'owner'],[$admin,'admin']] as [$user,$role]) {
            $user->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$user->id,'role'=>$role]);
        }
        $this->be($owner);
        $catalog=app(HallInventoryCatalogService::class);
        $items=[];
        foreach([['Stacking chairs',40,10],['Serving trays',20,5]] as [$name,$owned,$send]) {
            $item=(string)Str::uuid();
            $catalog->create($owner,$company->id,$source->id,$item,
                $name,'Furniture','piece',2,'Owned dispatch catalog');
            DB::table('hall_inventory_positions')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                'hall_id'=>$source->id,'item_id'=>$item,'owned_usable'=>$owned,
                'revision'=>0,'created_at'=>now(),'updated_at'=>now()
            ]);
            $items[$item]=['owned'=>$owned,'send'=>$send];
        }
        config()->set('inventory_controls.loan_drafts_enabled',true);
        $loan=(string)Str::uuid();
        $lines=[];
        foreach($items as $itemId=>$info)
            $lines[]=['itemId'=>$itemId,'quantity'=>$info['send']];
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,$loan,
            'internal',$source->id,$dest->id,$lines,
            '2027-06-10','Move stage stock for event');
        $svc=app(HallInventoryInternalLoanDispatchService::class);
        $dispatchId=(string)Str::uuid();
        $revisions=array_fill_keys(array_keys($items),0);
        $pdf=fn()=>UploadedFile::fake()->createWithContent('handover.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->be($admin);
        try {
            $svc->dispatch($admin,$company->id,$loan,$dispatchId,$revisions,$pdf());
            $this->fail('Dispatch happened while release switch OFF.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_dispatch_enabled',true);
        $stale=$revisions;
        $stale[array_key_first($stale)]=1;
        try {
            $svc->dispatch($admin,$company->id,$loan,$dispatchId,$stale,$pdf());
            $this->fail('Stale position revision accepted.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_loan_dispatches')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->be($owner);
        try {
            $svc->dispatch($owner,$company->id,$loan,$dispatchId,$revisions,$pdf());
            $this->fail('Draft creator self-dispatched custody.');
        }catch(AuthorizationException){}
        $this->be($admin);
        $this->assertSame($dispatchId,
            $svc->dispatch($admin,$company->id,$loan,$dispatchId,$revisions,$pdf()));
        $this->assertSame($dispatchId,
            $svc->dispatch($admin,$company->id,$loan,$dispatchId,$revisions,$pdf()));
        foreach($items as $itemId=>$info) {
            $pos=DB::table('hall_inventory_positions')
                ->where('item_id',$itemId)->first();
            $this->assertSame($info['owned']-$info['send'],(int)$pos->owned_usable);
            $this->assertSame($info['send'],(int)$pos->owned_on_loan);
            $this->assertSame(0,(int)$pos->borrowed_usable);
            $this->assertSame(1,(int)$pos->revision);
        }
        $this->assertSame(1,DB::table('hall_inventory_loan_dispatches')->count());
        $this->assertSame(2,DB::table('hall_inventory_loan_dispatch_lines')->count());
        $alienItem=(string)Str::uuid();
        $this->be($owner);
        $catalog->create($owner,$company->id,$source->id,$alienItem,
            'Unrelated lamps','Lighting','piece',1,'Other valid source catalog item');
        $this->be($admin);
        $firstMovement=DB::table('hall_inventory_movement_events')
            ->where('event_kind','dispatch_owned_loan')->value('id');
        try {
            DB::transaction(function()use($company,$source,$dispatchId,$alienItem,$firstMovement):void {
                DB::table('hall_inventory_loan_dispatch_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                    'dispatch_id'=>$dispatchId,'hall_id'=>$source->id,
                    'item_id'=>$alienItem,'movement_id'=>$firstMovement,'quantity'=>1
                ]);
            });
            $this->fail('Cross-item valid movement was bound to unrelated dispatch line.');
        }catch(\Illuminate\Database\QueryException){}

        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('event_kind','dispatch_owned_loan')->count());
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.internal_loan_source_dispatched')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        try {
            $svc->dispatch($admin,$company->id,$loan,(string)Str::uuid(),$revisions,$pdf());
            $this->fail('Conflicting dispatch replay accepted.');
        }catch(ConflictHttpException){}
        $evidencePath=DB::table('hall_inventory_loan_dispatches')
            ->where('id',$dispatchId)->value('evidence_storage_path');
        Storage::disk('local')->put($evidencePath,'damaged-hand-over-file');
        try {
            $svc->dispatch($admin,$company->id,$loan,$dispatchId,$revisions,$pdf());
            $this->fail('Tampered private handover attachment replay accepted.');
        }catch(ConflictHttpException){}
        try {
            DB::transaction(fn()=>DB::table('hall_inventory_loan_dispatch_lines')
                ->where('dispatch_id',$dispatchId)->delete());
            $this->fail('Immutable dispatch line deleted.');
        }catch(\Illuminate\Database\QueryException){}
        $this->assertSame(2,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
    }
}
