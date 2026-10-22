<?php
namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
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
use App\Services\HallInventoryInternalLoanReturnAcceptanceService;
use App\Services\HallInventoryInternalLateReturnService;
use App\Services\HallInventoryInternalLoanReturnReview;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryInternalLateReturnTest extends TestCase
{
    use RefreshDatabase;

    private function proof():UploadedFile {
        return UploadedFile::fake()->createWithContent('original-signed-arrival.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }

    public function test_witnessed_late_receipt_reconciles_original_partial_batch_without_issuing_money_or_closure():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Later original custody','slug'=>'later-original-custody']);
        $source=Hall::create(['company_id'=>$company->id,'name'=>'Lending hall','slug'=>'later-source','capacity'=>100]);
        $borrower=Hall::create(['company_id'=>$company->id,'name'=>'Borrowing hall','slug'=>'later-borrower','capacity'=>100]);
        $owner=User::create(['name'=>'Initial source witness','email'=>'later-source-owner@example.test','password'=>'test-only']);
        $admin=User::create(['name'=>'Separate later source witness','email'=>'later-source-admin@example.test','password'=>'test-only']);
        $manager=User::create(['name'=>'Borrowing manager','email'=>'later-borrower-manager@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$admin,'admin'],[$manager,'manager']] as [$actor,$role]) {
            $actor->markEmailAsVerified();
            $membership=CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$actor->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create(['company_id'=>$company->id,'hall_id'=>$borrower->id,'membership_id'=>$membership->id]);
        }
        foreach(['catalog_enabled','loan_drafts_enabled','loan_dispatch_enabled','loan_receipt_enabled',
            'loan_returns_enabled','loan_return_acceptance_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $this->be($owner);
        $catalog=app(HallInventoryCatalogService::class);
        $item=(string)Str::uuid();$mapped=(string)Str::uuid();
        $catalog->create($owner,$company->id,$source->id,$item,'Later recovered tray','Furniture','piece',1,'Original source catalog');
        $catalog->create($owner,$company->id,$borrower->id,$mapped,'Later recovered tray','Furniture','piece',1,'Original borrower catalog');
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,'hall_id'=>$source->id,
            'item_id'=>$item,'owned_usable'=>30,'revision'=>0,'created_at'=>now(),'updated_at'=>now()
        ]);
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,$loan,'internal',
            $source->id,$borrower->id,[['itemId'=>$item,'quantity'=>12]],'2027-12-30',
            'Original return with later located items');
        $this->be($admin);
        app(HallInventoryInternalLoanDispatchService::class)->dispatch($admin,$company->id,
            $loan,(string)Str::uuid(),[$item=>0],$this->proof());
        $this->be($manager);
        app(HallInventoryInternalLoanReceiptService::class)->receive($manager,$company->id,
            $loan,(string)Str::uuid(),[$item=>$mapped],[$mapped=>0],$this->proof());
        $batch=(string)Str::uuid();
        app(HallInventoryInternalLoanReturnService::class)->send($manager,$company->id,
            $loan,$batch,[$item=>5],[$mapped=>1],$this->proof());
        $this->be($owner);
        app(HallInventoryInternalLoanReturnAcceptanceService::class)->accept($owner,$company->id,
            $batch,(string)Str::uuid(),[$item=>['good'=>3,'damaged'=>1]],
            [$item=>1],[$mapped=>2],$this->proof());
        $svc=app(HallInventoryInternalLateReturnService::class);
        $id=(string)Str::uuid();
        $this->be($admin);
        try {
            $svc->record($admin,$company->id,$batch,$id,$item,1,0,3,3,$this->proof());
            $this->fail('Default-OFF later physical movement posted.');
        }catch(ConflictHttpException) {}
        config()->set('inventory_controls.internal_late_return_enabled',true);
        $this->be($owner);
        try {
            $svc->record($owner,$company->id,$batch,$id,$item,1,0,3,3,$this->proof());
            $this->fail('First source acceptance witness self-attested subsequent physical discovery.');
        }catch(AuthorizationException) {}
        $this->be($manager);
        try {
            $svc->record($manager,$company->id,$batch,$id,$item,1,0,3,3,$this->proof());
            $this->fail('Borrowing manager was allowed to accept original source physical arrival.');
        }catch(AuthorizationException) {}
        $this->be($admin);
        try {
            $svc->record($admin,$company->id,$batch,$id,$item,1,0,2,3,$this->proof());
            $this->fail('Stale source stock revision accepted.');
        }catch(ConflictHttpException) {}
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$source->id)
            ->call('startLatePhysicalReturn',$batch,$item)->assertHasNoErrors()
            ->assertSee('Later independently witnessed physical arrival');
        $this->assertSame($id,$svc->record($admin,$company->id,$batch,$id,$item,1,0,3,3,$this->proof()));
        $this->assertSame($id,$svc->record($admin,$company->id,$batch,$id,$item,1,0,3,3,$this->proof()));
        try {
            $svc->record($admin,$company->id,$batch,$id,$item,0,1,4,4,$this->proof());
            $this->fail('Conflicting immutable later physical evidence replay.');
        }catch(ConflictHttpException) {}
        try {
            $svc->record($admin,$company->id,$batch,(string)Str::uuid(),$item,1,0,4,4,$this->proof());
            $this->fail('Original one unresolved unit double-received.');
        }catch(ConflictHttpException) {}
        $sourcePos=DB::table('hall_inventory_positions')->where('company_id',$company->id)
            ->where('hall_id',$source->id)->where('item_id',$item)->first();
        $destPos=DB::table('hall_inventory_positions')->where('company_id',$company->id)
            ->where('hall_id',$borrower->id)->where('item_id',$mapped)->first();
        $this->assertSame(22,(int)$sourcePos->owned_usable);
        $this->assertSame(7,(int)$sourcePos->owned_on_loan);
        $this->assertSame(1,(int)$sourcePos->owned_damaged);
        $this->assertSame(0,(int)$destPos->borrowed_transit);
        $this->assertSame(7,(int)$destPos->borrowed_usable);
        $history=app(HallInventoryInternalLoanReturnReview::class)
            ->sourceBatches($admin,$company->id,$source->id);
        $this->assertSame(1,$history[0]['lines'][0]['lateGood']);
        $this->assertSame(0,$history[0]['lines'][0]['remainingUnresolved']);
        $physical=app(HallInventoryInternalLoanReturnReview::class)
            ->abnormalSourceLines($admin,$company->id,$source->id,$loan);
        $this->assertSame(4,$physical[0]['good']);
        $this->assertSame(1,$physical[0]['damaged']);
        $this->assertSame(0,$physical[0]['unresolved']);
        $this->assertSame(7,$physical[0]['notReturned']);
        $this->assertSame(1,DB::table('hall_inventory_internal_late_returns')->count());
        $this->assertSame(1,DB::table('audit_events')->where('action',
            'hall_inventory.internal_original_late_return_physically_accepted')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_closures')->count());
        $this->assertSame(0,DB::table('hall_inventory_nonclean_final_closures')->count());
        $row=DB::table('hall_inventory_internal_late_returns')->first();
        $proof=app(HallInventoryInternalLateReturnService::class)->privateProof(
            $admin,$company->id,$source->id,$id);
        $this->assertSame('application/pdf',$proof['mime']);
        $this->assertSame(1,DB::table('audit_events')->where('action',
            'hall_inventory.internal_late_original_physical_proof_read')->count());
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$source->id)->call('downloadLatePhysicalReturnProof',$id)
            ->assertHasNoErrors();
        $this->assertSame(2,DB::table('audit_events')->where('action',
            'hall_inventory.internal_late_original_physical_proof_read')->count());
        $this->be($manager);
        try {
            app(HallInventoryInternalLateReturnService::class)->privateProof(
                $manager,$company->id,$source->id,$id);
            $this->fail('Borrower could download private original-lending-hall proof.');
        }catch(AuthorizationException) {}
        $this->be($admin);
        $disk=Storage::disk('local');$original=$disk->get($row->evidence_storage_path);
        $disk->put($row->evidence_storage_path,'tampered');
        try {
            app(HallInventoryInternalLoanReturnReview::class)
                ->sourceBatches($admin,$company->id,$source->id);
            $this->fail('Tampered late return evidence displayed as trusted physical custody.');
        }catch(ConflictHttpException) {}
        $disk->put($row->evidence_storage_path,$original);
        // Privileged immutability bypass is NOT assumed safe: reject an
        // append-only extra row that reuses unrelated old movements as a
        // supposed newly witnessed receipt.
        $accepted=DB::table('hall_inventory_internal_loan_return_acceptances')->first();
        $acceptedLine=DB::table('hall_inventory_internal_loan_return_acceptance_lines')->first();
        $forged=(string)Str::uuid();
        $forgedPath='private/hall-internal-late-return/'.$company->id.'/'.$batch.'/'.$forged;
        $disk->put($forgedPath,$original);
        DB::table('hall_inventory_internal_late_returns')->insert([
            'id'=>$forged,'company_id'=>$company->id,'loan_id'=>$loan,
            'return_batch_id'=>$batch,'acceptance_id'=>$accepted->id,
            'source_hall_id'=>$source->id,'source_item_id'=>$item,'dest_item_id'=>$mapped,
            'good_quantity'=>1,'damaged_quantity'=>0,
            'good_movement_id'=>$acceptedLine->source_good_movement_id,
            'damaged_movement_id'=>null,
            'dest_movement_id'=>$acceptedLine->destination_movement_id,
            'evidence_sha256'=>hash('sha256',$original),'evidence_size'=>strlen($original),
            'evidence_storage_path'=>$forgedPath,'fingerprint'=>str_repeat('a',64),
            'accepted_by'=>$admin->id
        ]);
        try {
            app(HallInventoryInternalLoanReturnReview::class)
                ->abnormalSourceLines($admin,$company->id,$source->id,$loan);
            $this->fail('Forged reuse of original acceptance stock movements was counted as new physical stock.');
        }catch(ConflictHttpException) {}
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$admin->id)->update(['is_active'=>false]);
        try {
            $svc->record($admin,$company->id,$batch,(string)Str::uuid(),$item,1,0,4,4,$this->proof());
            $this->fail('Revoked source witness posted an additional physical movement.');
        }catch(AuthorizationException) {}
    }
}
