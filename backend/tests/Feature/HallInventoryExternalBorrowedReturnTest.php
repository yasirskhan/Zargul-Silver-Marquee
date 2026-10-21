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
use App\Services\HallInventoryExternalBorrowedIntakeService;
use App\Services\HallInventoryExternalBorrowedReturnService;
use App\Services\HallInventoryExternalCleanLoanClosureService;
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

final class HallInventoryExternalBorrowedReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_partner_returns_require_signed_handover_and_independent_exact_receipt_without_owned_or_financial_posting():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Partner Return Regression','slug'=>'partner-return-regression']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Partner Return Hall',
            'slug'=>'partner-return-hall','capacity'=>150]);
        $foreign=Hall::create(['company_id'=>$company->id,'name'=>'Foreign Return Hall',
            'slug'=>'foreign-return-hall','capacity'=>100]);
        $drafter=User::create(['name'=>'Partner Draft Creator',
            'email'=>'return-drafter@example.test','password'=>'test-only']);
        $handler=User::create(['name'=>'Return Handover Operator',
            'email'=>'return-handler@example.test','password'=>'test-only']);
        $owner=User::create(['name'=>'Independent Return Owner',
            'email'=>'return-owner@example.test','password'=>'test-only']);
        foreach([[$drafter,'manager'],[$handler,'manager'],[$owner,'owner']] as [$u,$role]) {
            $u->markEmailAsVerified();
            $m=CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'hall_id'=>$hall->id,'membership_id'=>$m->id
            ]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach(['catalog_enabled','loan_drafts_enabled','external_borrowed_intake_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $this->be($drafter);
        $catalog=app(HallInventoryCatalogService::class);
        $requested=[];
        foreach([['Supplier folding tables',6],['Supplier light towers',4]] as [$name,$qty]) {
            $item=(string)Str::uuid();
            $catalog->create($drafter,$company->id,$hall->id,$item,$name,
                'Partner borrowed equipment','piece',1,'Supplier return test catalog');
            $requested[]=['itemId'=>$item,'quantity'=>$qty];
        }
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,$loan,
            'external_in',null,$hall->id,$requested,'2027-11-30',
            'Partial return independent verification test','Partner Hall','Signed counterparty');
        $handover=static fn()=>UploadedFile::fake()->createWithContent(
            'signed-handover.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $partnerAck=static fn()=>UploadedFile::fake()->createWithContent(
            'partner-signed-received.pdf',"%PDF-1.4\n2 0 obj\n<<>>\nendobj\n%%EOF");
        $counts=[];
        foreach($requested as $l)$counts[$l['itemId']]=$l['quantity'];
        $this->be($handler);
        $receipt=(string)Str::uuid();
        app(HallInventoryExternalBorrowedIntakeService::class)->record(
            $handler,$company->id,$hall->id,$loan,$receipt,$handover(),$counts);
        $this->be($owner);
        app(HallInventoryExternalBorrowedIntakeService::class)->accept(
            $owner,$company->id,$hall->id,$loan,(string)Str::uuid());

        $return=app(HallInventoryExternalBorrowedReturnService::class);
        $firstQuantities=[$requested[0]['itemId']=>2,$requested[1]['itemId']=>1];
        $this->be($handler);
        try {
            $return->dispatch($handler,$company->id,$hall->id,$loan,
                (string)Str::uuid(),$firstQuantities,$handover());
            $this->fail('External borrowed returns ignored their own release gate.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.external_borrowed_return_enabled',true);
        $this->be($drafter);
        Livewire::test(HallInventory::class)
            ->assertDontSee('Prepare partial outside partner return')
            ->call('startExternalBorrowedReturn',$loan)->assertForbidden();
        $this->be($handler);
        Livewire::test(HallInventory::class)
            ->assertSee('Prepare partial outside partner return')
            ->call('startExternalBorrowedReturn',$loan)->assertHasNoErrors()
            ->set('externalReturnQuantities.'.$requested[0]['itemId'],'2')
            ->set('externalReturnQuantities.'.$requested[1]['itemId'],'1')
            ->set('externalReturnFile',$handover())
            ->call('submitExternalBorrowedReturn')->assertHasNoErrors()
            ->assertSee('IN TRANSIT / PARTNER ACKNOWLEDGMENT PENDING');
        $batch=(string)DB::table('hall_inventory_external_in_return_batches')
            ->where('company_id',$company->id)->where('loan_id',$loan)->value('id');
        $this->assertTrue(Str::isUuid($batch));
        $this->assertSame(2,DB::table('hall_inventory_external_in_return_lines')->count());
        $this->assertSame($batch,$return->dispatch($handler,$company->id,$hall->id,
            $loan,$batch,$firstQuantities,$handover()));
        try {
            $return->dispatch($handler,$company->id,$hall->id,
                $loan,$batch,[$requested[0]['itemId']=>1],$handover());
            $this->fail('Idempotent replay changed a signed return quantity.');
        }catch(ConflictHttpException){}
        try {
            $return->dispatch($handler,$company->id,$foreign->id,
                $loan,(string)Str::uuid(),$firstQuantities,$handover());
            $this->fail('Foreign-hall external return was accepted.');
        }catch(ConflictHttpException){}
        try {
            $return->dispatch($handler,$company->id,$hall->id,
                $loan,(string)Str::uuid(),[$requested[0]['itemId']=>5],$handover());
            $this->fail('Second partial return exceeded the same loan amount.');
        }catch(ConflictHttpException){}
        $this->assertSame(1,DB::table('hall_inventory_external_in_return_batches')->count());
        foreach($requested as $l) {
            $position=DB::table('hall_inventory_positions')->where('company_id',$company->id)
                ->where('item_id',$l['itemId'])->first();
            $shipped=$firstQuantities[$l['itemId']];
            $this->assertSame($l['quantity']-$shipped,(int)$position->borrowed_usable);
            $this->assertSame($shipped,(int)$position->borrowed_transit);
            $this->assertSame(0,(int)$position->owned_usable);
        }
        try {
            $return->confirm($handler,$company->id,$hall->id,$batch,
                (string)Str::uuid(),$firstQuantities,$partnerAck());
            $this->fail('A dispatcher could independently accept their own return.');
        }catch(AuthorizationException){}
        $this->be($owner);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Independently verify partner receipt')
            ->call('startExternalReturnConfirmation',$batch)->assertHasNoErrors()
            ->set('externalConfirmCounts.'.$requested[0]['itemId'],'1')
            ->set('externalConfirmCounts.'.$requested[1]['itemId'],'1')
            ->set('externalConfirmFile',$partnerAck())
            ->call('submitExternalReturnConfirmation')
            ->assertHasErrors(['externalConfirmCounts']);
        $this->assertSame(0,DB::table('hall_inventory_external_in_return_confirmations')->count());
        $this->assertSame(3,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        $this->be($owner);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->call('startExternalReturnConfirmation',$batch)->assertHasNoErrors()
            ->set('externalConfirmCounts.'.$requested[0]['itemId'],'2')
            ->set('externalConfirmCounts.'.$requested[1]['itemId'],'1')
            ->set('externalConfirmFile',$partnerAck())
            ->call('submitExternalReturnConfirmation')->assertHasNoErrors()
            ->assertSee('PARTNER PHYSICAL RECEIPT CONFIRMED');
        $confirmation=DB::table('hall_inventory_external_in_return_confirmations')
            ->where('batch_id',$batch)->first();
        $this->assertNotNull($confirmation);
        $this->assertSame($confirmation->id,$return->confirm($owner,$company->id,$hall->id,
            $batch,$confirmation->id,$firstQuantities,$partnerAck()));
        try {
            $return->confirm($owner,$company->id,$hall->id,
                $batch,(string)Str::uuid(),$firstQuantities,$partnerAck());
            $this->fail('Duplicate partner acknowledgement was created.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('EXTERNAL PARTIAL RETURN / PARTNER RECEIPT PENDING')
            ->assertSee('total outstanding 7');
        config()->set('inventory_controls.external_borrowed_clean_closure_enabled',true);
        try {
            app(HallInventoryExternalCleanLoanClosureService::class)->close(
                $owner,$company->id,$loan,(string)Str::uuid());
            $this->fail('Partially returned borrowed loan was incorrectly closed.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.external_borrowed_clean_closure_enabled',false);
        $this->assertSame(2,DB::table('hall_inventory_external_in_return_confirmation_lines')->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('event_kind','confirm_borrowed_return')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('owned_usable'));
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_closures')->count());

        // Direct SQL must not relink a return line to a valid same-item
        // acceptance movement instead of the exact send-to-partner transition.
        $acceptMovement=DB::table('hall_inventory_external_in_acceptance_lines')
            ->where('item_id',$requested[0]['itemId'])->value('movement_id');
        try {
            DB::transaction(function()use($company,$hall,$batch,$requested,$acceptMovement):void {
                DB::table('hall_inventory_external_in_return_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$company->id,'hall_id'=>$hall->id,
                    'batch_id'=>$batch,'item_id'=>$requested[0]['itemId'],
                    'movement_id'=>$acceptMovement,'quantity'=>6
                ]);
            });
            $this->fail('Forged SQL lineage bypassed exact external return transit movement.');
        }catch(QueryException $e) {
            $this->assertStringContainsString('External return line must reference accepted borrowed loan', $e->getMessage());
        }
        try {
            DB::transaction(function()use($batch):void {
                DB::table('hall_inventory_external_in_return_batches')
                    ->where('id',$batch)->delete();
            });
            $this->fail('Immutable return custody was deletable.');
        }catch(QueryException){}
        $this->assertSame(1,DB::table('hall_inventory_external_in_return_batches')->count());
        $this->be($handler);
        $remaining=[$requested[0]['itemId']=>4,$requested[1]['itemId']=>3];
        $second=(string)Str::uuid();
        $return->dispatch($handler,$company->id,$hall->id,$loan,
            $second,$remaining,$handover());
        $this->be($owner);
        $return->confirm($owner,$company->id,$hall->id,$second,
            (string)Str::uuid(),$remaining,$partnerAck());
        $this->assertSame(2,DB::table('hall_inventory_external_in_return_batches')->count());
        $this->assertSame(2,DB::table('hall_inventory_external_in_return_confirmations')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('borrowed_usable'));
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('owned_usable'));
        $this->assertSame(0,DB::table('finance_journals')->count());
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('EXTERNAL RETURN PHYSICALLY VERIFIED / FINANCE UNSETTLED')
            ->assertSee('total outstanding 0')
            ->assertSee('Formal loan closure, claims and financial settlement remain separate');

        // Full physical handover alone does not mint a numbered closure or a
        // financial settlement. Only the separately approved clean closeout
        // workflow creates a permanent immutable receipt.
        $closure=app(HallInventoryExternalCleanLoanClosureService::class);
        $closureId=(string)Str::uuid();
        try {
            $closure->close($owner,$company->id,$loan,$closureId);
            $this->fail('Clean closeout ignored separate feature release gate.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.external_borrowed_clean_closure_enabled',true);
        $this->be($drafter);
        try {
            $closure->close($drafter,$company->id,$loan,$closureId);
            $this->fail('Draft creator self-closed partner loan.');
        }catch(AuthorizationException){}
        $this->be($handler);
        try {
            $closure->close($handler,$company->id,$loan,$closureId);
            $this->fail('Return dispatcher self-closed partner loan.');
        }catch(AuthorizationException){}
        $this->be($owner);
        // SQL-level exact original hall binding, not merely a valid company hall.
        try {
            DB::transaction(function()use($company,$hall,$foreign,$loan,$closureId,$owner):void {
                DB::table('hall_inventory_external_in_clean_closures')->insert([
                    'id'=>$closureId,'company_id'=>$company->id,'loan_id'=>$loan,
                    'hall_id'=>$foreign->id,'receipt_number'=>987654,
                    'reconciliation_sha256'=>str_repeat('a',64),
                    'pdf_sha256'=>str_repeat('b',64),'pdf_size'=>10,
                    'pdf_storage_path'=>'private/forged.pdf',
                    'closed_by'=>$owner->id,
                ]);
            });
            $this->fail('Foreign hall was incorrectly bound to accepted external loan closeout.');
        }catch(QueryException $e) {
            $this->assertStringContainsString('ext_in_clean_exact_acceptance_fk',$e->getMessage());
        }
        $handoverRow=DB::table('hall_inventory_external_in_return_confirmations')
            ->where('batch_id',$batch)->first();
        $disk=Storage::disk('local');
        $original=$disk->get($handoverRow->evidence_storage_path);
        $disk->put($handoverRow->evidence_storage_path,'counterparty acknowledgement tampered');
        try {
            $closure->close($owner,$company->id,$loan,$closureId);
            $this->fail('Clean closeout ignored tampered partner signed evidence.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_external_in_clean_closures')->count());
        $disk->put($handoverRow->evidence_storage_path,$original);
        $occupiedPath='private/hall-external-clean-return-closures/'.$company->id.'/'.$closureId.'.pdf';
        $occupiedPdf="%PDF-1.4\nExisting external borrowing evidence\n%%EOF\n";
        $disk->put($occupiedPath,$occupiedPdf);
        try {
            $closure->close($owner,$company->id,$loan,$closureId);
            $this->fail('Existing external borrowed numbered receipt was overwritten.');
        } catch (ConflictHttpException) {}
        $this->assertSame($occupiedPdf,$disk->get($occupiedPath));
        $this->assertSame(0,DB::table('hall_inventory_external_in_clean_closures')->count());
        $disk->delete($occupiedPath);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Finalize externally borrowed clean loan')
            ->call('closeCleanExternalBorrowing',$loan)->assertHasNoErrors()
            ->assertSee('External borrowed clean return receipt EX-1')
            ->assertSee('Download numbered external clean-return PDF');
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('CLOSED / EXTERNAL CLEAN PARTNER RETURN VERIFIED')
            ->assertSee('Clean physical return closure is recorded');
        $stored=DB::table('hall_inventory_external_in_clean_closures')
            ->where('company_id',$company->id)->where('loan_id',$loan)->first();
        $this->assertNotNull($stored);
        $this->assertSame(1,(int)$stored->receipt_number);
        $this->assertSame($closure->summaryForLoan($owner,$company->id,$loan)['id'],$stored->id);
        $this->assertSame($stored->id,$closure->close($owner,$company->id,$loan,$stored->id)['id']);
        $pdf=$closure->receiptPdf($owner,$company->id,$loan);
        $this->assertStringStartsWith('%PDF-1.4',$pdf);
        $this->assertStringContainsString('Receipt EX-1',$pdf);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('downloadExternalCleanReceipt',$loan)
            ->assertFileDownloaded('venue-inventory-external-clean-loan-'.substr($loan,0,8).'.pdf');
        Livewire::test(HallInventory::class)->set('hallId',$foreign->id)
            ->call('downloadExternalCleanReceipt',$loan)->assertForbidden();
        $this->be($handler);
        try {
            $closure->receiptPdf($handler,$company->id,$loan);
            $this->fail('Outside return PDF accessible to a hall manager.');
        }catch(AuthorizationException){}
        $this->be($owner);
        try {
            $closure->close($owner,$company->id,$loan,(string)Str::uuid());
            $this->fail('Second distinct numbered external clean return was issued.');
        }catch(ConflictHttpException){}
        try {
            DB::transaction(function()use($stored):void {
                DB::table('hall_inventory_external_in_clean_closures')
                    ->where('id',$stored->id)->delete();
            });
            $this->fail('Immutable numbered partner closure was deletable.');
        }catch(QueryException){}
        $disk->put($stored->pdf_storage_path,'tampered immutable receipt');
        try {
            $closure->receiptPdf($owner,$company->id,$loan);
            $this->fail('Tampered PDF still served.');
        }catch(ConflictHttpException){}
        $this->assertSame(1,DB::table('hall_inventory_external_in_clean_closures')->count());
        $this->be($owner);
        $closed=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($owner,$company->id,$hall->id))->firstWhere('id',$loan);
        $this->assertTrue($closed['externalCleanClosed']);
        $this->assertTrue($closed['externalReturnReconciliation']['loanClosed']);
        $this->assertSame('external_clean_loan_closed',$closed['status']);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertDontSee('Prepare partial outside partner return');
        $this->be($handler);
        try {
            $return->dispatch($handler,$company->id,$hall->id,$loan,
                (string)Str::uuid(),[$requested[0]['itemId']=>1],$handover());
            $this->fail('New physical shipment was allowed after immutable closeout.');
        }catch(ConflictHttpException $e) {
            $this->assertStringContainsString('already independently closed',$e->getMessage());
        }
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('owned_usable'));
    }
}
