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

final class HallInventoryExternalOutDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_handover_and_three_actor_owned_outside_dispatch_require_exact_counts_and_signed_evidence():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Owned Outside Lending Regression',
            'slug'=>'owned-outside-lending-regression']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Source Marquee',
            'slug'=>'source-marquee','capacity'=>150]);
        $other=Hall::create(['company_id'=>$company->id,'name'=>'Foreign Marquee',
            'slug'=>'foreign-marquee','capacity'=>99]);
        $drafter=User::create(['name'=>'Outside Loan Drafter',
            'email'=>'owned-out-draft@example.test','password'=>'test-only']);
        $witness=User::create(['name'=>'Independent Partner Handover Witness',
            'email'=>'owned-out-witness@example.test','password'=>'test-only']);
        $owner=User::create(['name'=>'Independent Outside Lender Owner',
            'email'=>'owned-out-owner@example.test','password'=>'test-only']);
        foreach([[$drafter,'manager'],[$witness,'manager'],[$owner,'owner']] as [$u,$role]){
            $u->markEmailAsVerified();
            $membership=CompanyMembership::create([
                'company_id'=>$company->id,'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'hall_id'=>$hall->id,
                'membership_id'=>$membership->id]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach(['catalog_enabled','loan_drafts_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $this->be($drafter);
        $catalog=app(HallInventoryCatalogService::class);
        $items=[];
        foreach([['Lending folding chairs',8],['Lending table toppers',3]] as [$name,$qty]){
            $id=(string)Str::uuid();
            $catalog->create($drafter,$company->id,$hall->id,$id,$name,
                'Outside rentals','piece',1,'Loan inventory fixture');
            DB::table('hall_inventory_positions')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                'hall_id'=>$hall->id,'item_id'=>$id,'owned_usable'=>$qty+6,
                'owned_on_loan'=>0,'revision'=>0,
                'created_at'=>now(),'updated_at'=>now()
            ]);
            $items[]=['itemId'=>$id,'quantity'=>$qty];
        }
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,
            $loan,'external_out',$hall->id,null,$items,'2027-12-10',
            'Independent outside owned lending physical handover',
            'External Guest Partner','Signed outside counterparty');
        $svc=app(HallInventoryExternalOutDispatchService::class);
        $pdf=static fn()=>UploadedFile::fake()->createWithContent(
            'partner-signed-handover.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $counts=[];
        foreach($items as $item)$counts[$item['itemId']]=$item['quantity'];
        $handover=(string)Str::uuid();
        $this->be($witness);
        try{
            $svc->record($witness,$company->id,$hall->id,$loan,$handover,$pdf(),$counts);
            $this->fail('Outside lending recorded before feature release.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.external_out_dispatch_enabled',true);
        $this->be($drafter);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertDontSee('Witness outside partner physical handover')
            ->call('startExternalOutHandover',$loan)->assertForbidden();
        $this->be($witness);
        try {
            $svc->record($witness,$company->id,$other->id,$loan,(string)Str::uuid(),
                $pdf(),$counts);
            $this->fail('Outside lending accepted a foreign hall.');
        }catch(ConflictHttpException){}
        // Record an ACTUAL physical shortfall; do not post owned stock.
        $short=$counts;
        $short[$items[0]['itemId']]--;
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('Witness outside partner physical handover')
            ->call('startExternalOutHandover',$loan)->assertHasNoErrors()
            ->set('externalOutCounts.'.$items[0]['itemId'],(string)$short[$items[0]['itemId']])
            ->set('externalOutCounts.'.$items[1]['itemId'],(string)$short[$items[1]['itemId']])
            ->set('externalOutFile',$pdf())
            ->call('submitExternalOutHandover')->assertHasNoErrors()
            ->assertSee('OUTSIDE LENDING HANDOVER / INDEPENDENT REVIEW PENDING');
        $saved=(string)DB::table('hall_inventory_external_out_handovers')
            ->where('company_id',$company->id)->where('loan_id',$loan)->value('id');
        $this->assertTrue(Str::isUuid($saved));
        $this->assertSame(2,DB::table('hall_inventory_external_out_handover_lines')->count());
        $this->assertSame($saved,$svc->record($witness,$company->id,$hall->id,
            $loan,$saved,$pdf(),$short));
        try {
            $svc->record($witness,$company->id,$hall->id,
                $loan,$saved,$pdf(),$counts);
            $this->fail('Immutable physical handover count changed on replay.');
        }catch(ConflictHttpException){}
        $this->be($owner);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('Outside physical counts do not match original loan')
            ->assertDontSee('Independently approve outside lending stock dispatch');
        try {
            $svc->approve($owner,$company->id,$hall->id,$loan,(string)Str::uuid());
            $this->fail('Owner posted physically short outside loan.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_external_out_dispatches')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        // A signed witness cannot erase/re-file an immutable inaccurate handover.
        try {
            DB::transaction(fn()=>DB::table('hall_inventory_external_out_handover_lines')
                ->where('handover_id',$saved)->update(['counted_quantity'=>8]));
            $this->fail('Immutable outside physical count mutated.');
        }catch(QueryException){}
        // A separately recorded, exact loan must be independently approved.
        $second=(string)Str::uuid();
        $this->be($drafter);
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,
            $second,'external_out',$hall->id,null,$items,'2027-12-10',
            'Second independent partner owned inventory handover',
            'Second Outside Partner','New counterparty');
        $this->be($witness);
        $handoverTwo=(string)Str::uuid();
        $svc->record($witness,$company->id,$hall->id,$second,$handoverTwo,$pdf(),$counts);
        $this->be($witness);
        try {
            $svc->approve($witness,$company->id,$hall->id,$second,(string)Str::uuid());
            $this->fail('Physical witness self-approved outside-owned posting.');
        }catch(AuthorizationException){}
        $this->be($drafter);
        try{
            $svc->approve($drafter,$company->id,$hall->id,$second,(string)Str::uuid());
            $this->fail('Loan drafter approved their own outside stock dispatch.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $this->assertSame(0,DB::table('hall_inventory_external_out_dispatches')->count());
        // Original-loan foreign item must fail SQL composite provenance constraint.
        $unrelated=(string)Str::uuid();
        $catalog->create($owner,$company->id,$hall->id,$unrelated,
            'Unrelated outside stock','Furniture','piece',1,'SQL scope regression');
        try{
            DB::transaction(function()use($company,$hall,$second,$handoverTwo,$unrelated):void {
                DB::table('hall_inventory_external_out_handover_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                    'handover_id'=>$handoverTwo,'loan_id'=>$second,
                    'hall_id'=>$hall->id,'item_id'=>$unrelated,'counted_quantity'=>1
                ]);
            });
            $this->fail('Foreign original loan line was incorrectly bindable.');
        }catch(QueryException){}
        $dispatch=(string)Str::uuid();
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('Independently approve outside lending stock dispatch')
            ->call('approveExternalOutDispatch',$second)->assertHasNoErrors()
            ->assertSee('HALL-OWNED STOCK LENT OUT / PARTNER RETURN PENDING');
        $real=(string)DB::table('hall_inventory_external_out_dispatches')
            ->where('company_id',$company->id)->where('loan_id',$second)->value('id');
        $this->assertTrue(Str::isUuid($real));
        $this->assertSame($real,$svc->approve($owner,$company->id,$hall->id,$second,$real));
        try {
            $svc->approve($owner,$company->id,$hall->id,$second,$dispatch);
            $this->fail('Repeated outside stock posting bypassed immutable dispatch ID.');
        }catch(ConflictHttpException){}
        foreach($items as $item) {
            $p=DB::table('hall_inventory_positions')->where('company_id',$company->id)
                ->where('hall_id',$hall->id)->where('item_id',$item['itemId'])->first();
            $this->assertSame(6,(int)$p->owned_usable);
            $this->assertSame($item['quantity'],(int)$p->owned_on_loan);
            $this->assertSame(0,(int)$p->borrowed_usable);
        }
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('event_kind','dispatch_owned_loan')->count());
        $this->assertSame(2,DB::table('hall_inventory_external_out_dispatch_lines')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $stored=DB::table('hall_inventory_external_out_handovers')
            ->where('company_id',$company->id)->where('loan_id',$second)->first();
        Storage::disk('local')->put($stored->evidence_storage_path,'tampered witness evidence');
        try {
            $svc->approve($owner,$company->id,$hall->id,$second,$real);
            $this->fail('Tampered private partner document replayed.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.external_out_dispatch_enabled',false);
        try {
            $svc->approve($owner,$company->id,$hall->id,$second,$real);
            $this->fail('Disabled release gate did not block posting.');
        }catch(ConflictHttpException){}
    }
}