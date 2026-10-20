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
    }
}
