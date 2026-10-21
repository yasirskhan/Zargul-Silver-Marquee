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
use App\Services\HallInventoryExternalOutDispatchService;
use App\Services\HallInventoryExternalOutReturnService;
use App\Services\HallInventoryExternalOutCleanClosureService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class HallInventoryExternalOutCleanClosureTest extends TestCase {
    use RefreshDatabase;

    public function test_full_independent_multi_batch_good_outside_owned_returns_mint_immutable_numbered_pdf():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'External Owned Clean Closure Fixture','slug'=>'ext-owned-clean']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Owned Clean Source',
            'slug'=>'owned-clean-source','capacity'=>130]);
        $foreign=Hall::create(['company_id'=>$company->id,'name'=>'Other Owned Hall',
            'slug'=>'other-owned-hall','capacity'=>110]);
        $drafter=User::create(['name'=>'Outside Draft',
            'email'=>'owned-clean-drafter@example.test','password'=>'test-only']);
        $witness=User::create(['name'=>'Outside Physical Witness',
            'email'=>'owned-clean-witness@example.test','password'=>'test-only']);
        $owner=User::create(['name'=>'Outside Clean Owner',
            'email'=>'owned-clean-owner@example.test','password'=>'test-only']);
        foreach([[$drafter,'manager'],[$witness,'manager'],[$owner,'owner']] as [$u,$role]) {
            $u->markEmailAsVerified();
            $m=CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'hall_id'=>$hall->id,'membership_id'=>$m->id]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach(['catalog_enabled','loan_drafts_enabled','external_out_dispatch_enabled',
            'external_out_return_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $this->be($drafter);
        $items=[];
        foreach([['Clean outside dining tables',4],['Clean outside lighting',2]] as [$name,$qty]) {
            $itemId=(string)Str::uuid();
            app(HallInventoryCatalogService::class)->create($drafter,$company->id,$hall->id,
                $itemId,$name,'Owned rentals','piece',1,'Outside clean loan fixture');
            DB::table('hall_inventory_positions')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                'hall_id'=>$hall->id,'item_id'=>$itemId,'owned_usable'=>$qty+3,
                'revision'=>0,'created_at'=>now(),'updated_at'=>now()
            ]);
            $items[]=['itemId'=>$itemId,'quantity'=>$qty];
        }
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,$loan,
            'external_out',$hall->id,null,$items,'2027-12-15',
            'Outside owned-stock partner clean loan closure regression',
            'Outside Wedding Rental Partner','Signed partner');
        $pdf=static fn()=>UploadedFile::fake()->createWithContent(
            'clean-partner-signature.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $counts=[];foreach($items as $i)$counts[$i['itemId']]=$i['quantity'];
        $this->be($witness);
        $handover=(string)Str::uuid();
        app(HallInventoryExternalOutDispatchService::class)->record($witness,
            $company->id,$hall->id,$loan,$handover,$pdf(),$counts);
        $this->be($owner);
        app(HallInventoryExternalOutDispatchService::class)->approve(
            $owner,$company->id,$hall->id,$loan,(string)Str::uuid());
        $closure=app(HallInventoryExternalOutCleanClosureService::class);
        try {
            $closure->close($owner,$company->id,$loan,(string)Str::uuid());
            $this->fail('External clean closure was enabled before separate release.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.external_out_clean_closure_enabled',true);
        try {
            $closure->close($owner,$company->id,$loan,(string)Str::uuid());
            $this->fail('Unreturned external owned loan incorrectly closed.');
        }catch(ConflictHttpException){}
        $this->be($witness);
        $partial=[$items[0]['itemId']=>2,$items[1]['itemId']=>1];
        $return=app(HallInventoryExternalOutReturnService::class);
        $one=(string)Str::uuid();
        $return->receive($witness,$company->id,$hall->id,$loan,$one,$partial,$pdf());
        $this->be($owner);
        $return->review($owner,$company->id,$hall->id,$one,(string)Str::uuid(),[
            $items[0]['itemId']=>['good'=>2,'damaged'=>0,'unresolved'=>0],
            $items[1]['itemId']=>['good'=>1,'damaged'=>0,'unresolved'=>0]
        ]);
        try {
            $closure->close($owner,$company->id,$loan,(string)Str::uuid());
            $this->fail('Partial independently reviewed returns minted CLEAN receipt.');
        }catch(ConflictHttpException){}
        $this->be($witness);
        $last=[$items[0]['itemId']=>2,$items[1]['itemId']=>1];
        $two=(string)Str::uuid();
        $return->receive($witness,$company->id,$hall->id,$loan,$two,$last,$pdf());
        $this->be($owner);
        $return->review($owner,$company->id,$hall->id,$two,(string)Str::uuid(),[
            $items[0]['itemId']=>['good'=>2,'damaged'=>0,'unresolved'=>0],
            $items[1]['itemId']=>['good'=>1,'damaged'=>0,'unresolved'=>0]
        ]);
        $loanState=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($owner,$company->id,$hall->id))->firstWhere('id',$loan);
        $this->assertTrue($loanState['externalOutReturnReconciliation']['allFullyReturnedClean']);
        $this->assertSame(0,$loanState['externalOutReturnReconciliation']['stillOnLoan']);
        $this->assertFalse($loanState['overdueWithOutstandingCustody']);
        try {
            $closure->close($owner,$company->id,(string)Str::uuid(),(string)Str::uuid());
            $this->fail('Unknown external loan closed.');
        }catch(ConflictHttpException){}
        $storedReturn=DB::table('hall_inventory_external_out_return_receipts')
            ->where('id',$two)->first();
        $file=Storage::disk('local');
        $original=$file->get($storedReturn->evidence_storage_path);
        $file->put($storedReturn->evidence_storage_path,'forged return acknowledgment');
        try {
            $closure->close($owner,$company->id,$loan,(string)Str::uuid());
            $this->fail('Tampered physical source return accepted for finalization.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_external_out_clean_closures')->count());
        $file->put($storedReturn->evidence_storage_path,$original);
        // Final numbered certificates must reject forged duplicate batch rows,
        // whether SQL immutability blocks the mutation or reconciliation does.
        $originalLine=(array)DB::table('hall_inventory_external_out_return_lines')
            ->where('receipt_id',$two)->where('item_id',$items[0]['itemId'])->first();
        $this->assertNotEmpty($originalLine);
        try {
            DB::transaction(function () use ($originalLine,$closure,$owner,$company,$loan):void {
                $duplicate=$originalLine;
                $duplicate['id']=(string)Str::uuid();
                DB::table('hall_inventory_external_out_return_lines')->insert($duplicate);
                $closure->close($owner,$company->id,$loan,(string)Str::uuid());
                $this->fail('Duplicated physical batch row minted numbered PDF.');
            });
        } catch (QueryException|ConflictHttpException) {}
        $reviewLine=DB::table('hall_inventory_external_out_return_review_lines')
            ->where('receipt_id',$two)->where('item_id',$items[0]['itemId'])->first();
        $this->assertNotNull($reviewLine);
        $firstMovement=DB::table('hall_inventory_external_out_return_review_lines')
            ->where('receipt_id',$one)->where('item_id',$items[0]['itemId'])
            ->value('good_movement_id');
        try {
            DB::transaction(function () use ($reviewLine,$firstMovement,$closure,$owner,$company,$loan):void {
                DB::table('hall_inventory_external_out_return_review_lines')
                    ->where('id',$reviewLine->id)->update(['good_movement_id'=>$firstMovement]);
                $closure->close($owner,$company->id,$loan,(string)Str::uuid());
                $this->fail('Reused movement minted external owned clean PDF.');
            });
        } catch (QueryException|ConflictHttpException) {}
        $this->assertSame(0,DB::table('hall_inventory_external_out_clean_closures')->count());
        $this->be($witness);
        try{
            $closure->close($witness,$company->id,$loan,(string)Str::uuid());
            $this->fail('Manager self-closed outside stock return.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $occupiedId=(string)Str::uuid();
        $occupiedPath='private/hall-external-out-clean-closures/'.$company->id.'/'.$occupiedId.'.pdf';
        $occupiedPdf="%PDF-1.4\nExisting outside-owned clean evidence\n%%EOF\n";
        Storage::disk('local')->put($occupiedPath,$occupiedPdf);
        try {
            $closure->close($owner,$company->id,$loan,$occupiedId);
            $this->fail('Existing outside-owned numbered receipt was overwritten.');
        }catch(ConflictHttpException){}
        $this->assertSame($occupiedPdf,Storage::disk('local')->get($occupiedPath));
        $this->assertSame(0,DB::table('hall_inventory_external_out_clean_closures')->count());
        Storage::disk('local')->delete($occupiedPath);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('Finalize outside-owned clean physical loan')
            ->call('closeExternalOutCleanLoan',$loan)->assertHasNoErrors()
            ->assertSee('Immutable outside-owned clean physical return EOL-1')
            ->assertSee('Download numbered EOL outside-owned clean return PDF');
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('CLOSED / OUTSIDE OWNED CLEAN RETURN VERIFIED');
        $stored=DB::table('hall_inventory_external_out_clean_closures')
            ->where('company_id',$company->id)->where('loan_id',$loan)->first();
        $this->assertNotNull($stored);
        $this->assertSame(1,(int)$stored->receipt_number);
        $this->assertSame($stored->id,
            $closure->close($owner,$company->id,$loan,$stored->id)['id']);
        try {
            $closure->close($owner,$company->id,$loan,(string)Str::uuid());
            $this->fail('Conflicting number/repeated outside loan closure accepted.');
        }catch(ConflictHttpException){}
        try{
            DB::transaction(fn()=>DB::table('hall_inventory_external_out_clean_closures')
                ->where('id',$stored->id)->update(['receipt_number'=>5]));
            $this->fail('Numbered receipt was mutable.');
        }catch(QueryException){}
        $bytes=$closure->receiptPdf($owner,$company->id,$loan);
        $this->assertStringStartsWith('%PDF-1.4',$bytes);
        $this->assertStringContainsString('Receipt EOL-1',$bytes);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('downloadExternalOutCleanLoanPdf',$loan)
            ->assertFileDownloaded('venue-inventory-external-out-clean-'.substr($loan,0,8).'.pdf');
        Livewire::test(HallInventory::class)->set('hallId',$foreign->id)
            ->call('downloadExternalOutCleanLoanPdf',$loan)->assertForbidden();
        $this->be($witness);
        try{
            $closure->receiptPdf($witness,$company->id,$loan);
            $this->fail('Manager accessed owner-only EOL receipt.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_loan_incident_reports')->count());
        foreach($items as $item) {
            $position=DB::table('hall_inventory_positions')->where('company_id',$company->id)
                ->where('hall_id',$hall->id)->where('item_id',$item['itemId'])->first();
            $this->assertSame(0,(int)$position->owned_on_loan);
            $this->assertSame($item['quantity']+3,(int)$position->owned_usable);
        }
        $this->assertSame(2,DB::table('hall_inventory_external_out_return_reviews')->count());
    }
}