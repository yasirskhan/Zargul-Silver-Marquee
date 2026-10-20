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

final class HallInventoryExternalBorrowedIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_actors_are_required_before_external_borrowed_custody_and_never_owned_or_financial_stock():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'External Borrowed Custody','slug'=>'external-borrowed-custody']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Borrowing Hall',
            'slug'=>'external-borrowing-hall','capacity'=>100]);
        $foreignHall=Hall::create(['company_id'=>$company->id,'name'=>'Other Hall',
            'slug'=>'external-other-hall','capacity'=>100]);
        $drafter=User::create(['name'=>'Partner Loan Drafter',
            'email'=>'external-drafter@example.test','password'=>'test-only']);
        $counter=User::create(['name'=>'Partner Delivery Counter',
            'email'=>'external-counter@example.test','password'=>'test-only']);
        $owner=User::create(['name'=>'Independent Intake Owner',
            'email'=>'external-owner@example.test','password'=>'test-only']);
        foreach([[$drafter,'manager'],[$counter,'manager'],[$owner,'owner']] as [$u,$role]) {
            $u->markEmailAsVerified();
            $m=CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'hall_id'=>$hall->id,'membership_id'=>$m->id
            ]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach(['catalog_enabled','loan_drafts_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $this->be($drafter);
        $catalog=app(HallInventoryCatalogService::class);
        $lines=[];
        foreach([['Outside light stands',8],['Outside serving tables',3]] as [$name,$qty]) {
            $id=(string)Str::uuid();
            $catalog->create($drafter,$company->id,$hall->id,$id,
                $name,'Borrowed equipment','piece',1,'Outside vendor loan catalog');
            $lines[]=['itemId'=>$id,'quantity'=>$qty];
        }
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create(
            $drafter,$company->id,$loan,'external_in',null,$hall->id,$lines,
            '2027-11-30','Signed partner delivery, two equipment types',
            'External Event Equipment Vendor','Outside stock manager');
        $service=app(HallInventoryExternalBorrowedIntakeService::class);
        $file=fn()=>UploadedFile::fake()->createWithContent(
            'signed-external.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $receipt=(string)Str::uuid();
        $counts=[];
        foreach($lines as $line)$counts[$line['itemId']]=$line['quantity'];
        Livewire::test(HallInventory::class)
            ->assertDontSee('Record witnessed external borrowed delivery');
        $this->be($counter);
        try {
            $service->record($counter,$company->id,$hall->id,$loan,$receipt,$file(),$counts);
            $this->fail('External intake release switch ignored.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.external_borrowed_intake_enabled',true);
        $this->be($drafter);
        Livewire::test(HallInventory::class)
            ->assertDontSee('Record witnessed external borrowed delivery')
            ->call('startExternalBorrowedReceipt',$loan)->assertForbidden();
        $this->be($counter);
        Livewire::test(HallInventory::class)
            ->assertSet('hallId',$hall->id)
            ->assertSee('Record witnessed external borrowed delivery')
            ->call('startExternalBorrowedReceipt',$loan)->assertHasNoErrors()
            ->assertSee('Witnessed external borrowing, signed partner handover')
            ->set('externalIntakeFile',$file())
            ->set('externalIntakeCounts.'.(string)$lines[0]['itemId'],(string)$lines[0]['quantity'])
            ->set('externalIntakeCounts.'.(string)$lines[1]['itemId'],(string)$lines[1]['quantity'])
            ->call('submitExternalBorrowedReceipt')->assertHasNoErrors()
            ->assertSee('EXTERNAL PHYSICAL RECEIPT / INDEPENDENT REVIEW PENDING');
        $this->assertSame(1,DB::table('hall_inventory_external_in_receipts')->count());
        $this->assertSame(2,DB::table('hall_inventory_external_in_receipt_lines')->count());
        // The Livewire form owns its own locked receipt id, distinct from the
        // identifier used for the pre-release negative request above.
        $receipt=(string)DB::table('hall_inventory_external_in_receipts')
            ->where('company_id',$company->id)->where('loan_id',$loan)->value('id');
        // A valid hall item absent from the outside partner's original
        // immutable loan must not be attachable via direct PostgreSQL writes.
        $this->be($owner);
        $alien=(string)Str::uuid();
        $catalog->create($owner,$company->id,$hall->id,$alien,
            'Unrequested outside equipment','Furniture','piece',1,
            'SQL cross-item physical count guard regression');
        try {
            DB::transaction(function()use($company,$hall,$loan,$receipt,$alien):void {
                DB::table('hall_inventory_external_in_receipt_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                    'loan_id'=>$loan,'hall_id'=>$hall->id,
                    'receipt_id'=>$receipt,'item_id'=>$alien,'counted_quantity'=>1
                ]);
            });
            $this->fail('Foreign to the loan but valid hall item attached to immutable outside count.');
        } catch(QueryException $e) {
            $this->assertStringContainsString(
                'External borrowed physical count must match its own loan and hall item',
                $e->getMessage());
        }
        $this->be($counter);
        $this->assertSame(2,DB::table('hall_inventory_external_in_receipt_lines')->count());
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        try {
            $service->record($counter,$company->id,$foreignHall->id,$loan,$receipt,$file(),$counts);
            $this->fail('Another hall could bind partner receipt.');
        }catch(ConflictHttpException){}
        $this->assertSame(1,DB::table('hall_inventory_external_in_receipts')->count());
        $this->assertSame($receipt,$service->record(
            $counter,$company->id,$hall->id,$loan,$receipt,$file(),$counts));
        try {
            $service->accept($counter,$company->id,$hall->id,$loan,(string)Str::uuid());
            $this->fail('Counter self approved externally borrowed stock.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $physical=DB::table('hall_inventory_external_in_receipts')->where('loan_id',$loan)->first();
        $disk=Storage::disk('local');
        $original=$disk->get($physical->evidence_storage_path);
        $disk->put($physical->evidence_storage_path,'tampered outside signature');
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('Independently accept externally borrowed stock')
            ->call('approveExternalBorrowedReceipt',$loan)
            ->assertHasErrors(['externalIntakeFile']);
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $disk->put($physical->evidence_storage_path,$original);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('approveExternalBorrowedReceipt',$loan)->assertHasNoErrors()
            ->assertSee('EXTERNAL BORROWED STOCK RECEIVED / RETURN PENDING')
            ->assertSee('External inventory accepted as BORROWED');
        $accepted=DB::table('hall_inventory_external_in_acceptances')->where('loan_id',$loan)->first();
        $this->assertNotNull($accepted);
        $this->assertSame($accepted->id,$service->accept(
            $owner,$company->id,$hall->id,$loan,(string)$accepted->id));
        try {
            $service->accept($owner,$company->id,$hall->id,$loan,(string)Str::uuid());
            $this->fail('A second acceptance minted new stock.');
        }catch(ConflictHttpException){}
        $this->assertSame(2,DB::table('hall_inventory_external_in_acceptance_lines')->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('event_kind','accept_borrowed')->count());
        $this->assertSame(1,DB::table('hall_inventory_external_in_acceptances')->count());
        foreach($lines as $line) {
            $pos=DB::table('hall_inventory_positions')->where('company_id',$company->id)
                ->where('hall_id',$hall->id)->where('item_id',$line['itemId'])->first();
            $this->assertNotNull($pos);
            $this->assertSame($line['quantity'],(int)$pos->borrowed_usable);
            $this->assertSame(0,(int)$pos->owned_usable);
            $this->assertSame(0,(int)$pos->owned_on_loan);
            $this->assertSame(1,(int)$pos->revision);
        }
        $this->assertSame(0,DB::table('finance_journals')->count());
        try {
            DB::transaction(function()use($receipt):void {
                DB::table('hall_inventory_external_in_receipts')->where('id',$receipt)
                    ->update(['evidence_sha256'=>str_repeat('a',64)]);
            });
            $this->fail('External receiving evidence was mutable.');
        }catch(QueryException){}
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.external_borrowed_stock_accepted')->count());
        // A physical shortfall is retained as immutable count evidence,
        // NOT booked as if the supplier delivered all requested units.
        $this->be($drafter);
        $shortLoan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create(
            $drafter,$company->id,$shortLoan,'external_in',null,$hall->id,$lines,
            '2027-12-30','Partner sent one less item than draft',
            'External Equipment Vendor','Receiving department');
        $this->be($counter);
        $shortCount=$counts;
        $shortCount[$lines[0]['itemId']]-=1;
        $shortReceipt=(string)Str::uuid();
        $service->record($counter,$company->id,$hall->id,
            $shortLoan,$shortReceipt,$file(),$shortCount);
        Livewire::test(HallInventory::class)->assertSee(
            'Physical count differs from requested items.');
        $this->assertSame((int)$counts[$lines[0]['itemId']]-1,
            (int)DB::table('hall_inventory_external_in_receipt_lines')
                ->where('receipt_id',$shortReceipt)
                ->where('item_id',$lines[0]['itemId'])->value('counted_quantity'));
        try {
            $service->record($counter,$company->id,$hall->id,
                $shortLoan,$shortReceipt,$file(),$counts);
            $this->fail('Physical receipt replay changed counts.');
        }catch(ConflictHttpException){}
        $this->be($owner);
        Livewire::test(HallInventory::class)
            ->assertSee('Physical count differs from requested items.')
            ->assertDontSee('Independently accept externally borrowed stock');
        try {
            $service->accept($owner,$company->id,$hall->id,
                $shortLoan,(string)Str::uuid());
            $this->fail('Short-delivered units were credited to borrowed stock.');
        }catch(ConflictHttpException){}
        $this->assertSame(1,DB::table('hall_inventory_external_in_acceptances')->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->be($counter);
        Livewire::test(HallInventory::class)->assertDontSee(
            'Independently accept externally borrowed stock');
    }
}
