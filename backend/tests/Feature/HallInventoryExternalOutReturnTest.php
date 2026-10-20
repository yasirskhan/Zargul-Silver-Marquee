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

final class HallInventoryExternalOutReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_outside_owned_returns_require_partner_proof_independent_condition_and_keep_unresolved_units_on_loan():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Outside Owner Return Regression','slug'=>'outside-owner-return-regression']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Owner Rental Hall',
            'slug'=>'owner-rental-hall','capacity'=>100]);
        $other=Hall::create(['company_id'=>$company->id,'name'=>'Different Return Hall',
            'slug'=>'different-return-hall','capacity'=>125]);
        $drafter=User::create(['name'=>'External Owned Return Drafter',
            'email'=>'out-return-draft@example.test','password'=>'test-only']);
        $witness=User::create(['name'=>'Outside Physical Witness',
            'email'=>'out-return-witness@example.test','password'=>'test-only']);
        $owner=User::create(['name'=>'Independent Outside Return Owner',
            'email'=>'out-return-owner@example.test','password'=>'test-only']);
        foreach([[$drafter,'manager'],[$witness,'manager'],[$owner,'owner']] as [$u,$role]){
            $u->markEmailAsVerified();
            $member=CompanyMembership::create([
                'company_id'=>$company->id,'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'hall_id'=>$hall->id,
                'membership_id'=>$member->id]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach(['catalog_enabled','loan_drafts_enabled','external_out_dispatch_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $this->be($drafter);
        $catalog=app(HallInventoryCatalogService::class);
        $items=[];
        foreach([['Owned return folding tables',6],['Owned return lights',4]] as [$name,$qty]){
            $id=(string)Str::uuid();
            $catalog->create($drafter,$company->id,$hall->id,$id,$name,
                'External lending','piece',1,'Physical owned return fixture');
            DB::table('hall_inventory_positions')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                'hall_id'=>$hall->id,'item_id'=>$id,'owned_usable'=>$qty+6,
                'revision'=>0,'created_at'=>now(),'updated_at'=>now()
            ]);
            $items[]=['itemId'=>$id,'quantity'=>$qty];
        }
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,
            $loan,'external_out',$hall->id,null,$items,'2027-12-10',
            'Outside partner owned loan return condition review',
            'Outside Return Partner','Signed counterparty');
        $pdf=static fn()=>UploadedFile::fake()->createWithContent(
            'partner-return-proof.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $counts=[];
        foreach($items as $item)$counts[$item['itemId']]=$item['quantity'];
        $this->be($witness);
        app(HallInventoryExternalOutDispatchService::class)->record(
            $witness,$company->id,$hall->id,$loan,(string)Str::uuid(),$pdf(),$counts);
        $this->be($owner);
        app(HallInventoryExternalOutDispatchService::class)->approve(
            $owner,$company->id,$hall->id,$loan,(string)Str::uuid());

        $svc=app(HallInventoryExternalOutReturnService::class);
        $first=[$items[0]['itemId']=>3,$items[1]['itemId']=>1];
        $this->be($witness);
        try {
            $svc->receive($witness,$company->id,$hall->id,$loan,(string)Str::uuid(),
                $first,$pdf());
            $this->fail('Outside return ignored its independent release flag.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.external_out_return_enabled',true);
        $this->be($drafter);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertDontSee('Record partial owned-stock return from outside partner')
            ->call('startExternalOutReturn',$loan)->assertForbidden();
        $this->be($witness);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('Record partial owned-stock return from outside partner')
            ->call('startExternalOutReturn',$loan)->assertHasNoErrors()
            ->set('externalOutReturnQuantities.'.$items[0]['itemId'],'3')
            ->set('externalOutReturnQuantities.'.$items[1]['itemId'],'1')
            ->set('externalOutReturnFile',$pdf())
            ->call('submitExternalOutReturn')->assertHasNoErrors()
            ->assertSee('INDEPENDENT REVIEW PENDING');
        $receipt=(string)DB::table('hall_inventory_external_out_return_receipts')
            ->where('company_id',$company->id)->where('loan_id',$loan)->value('id');
        $this->assertTrue(Str::isUuid($receipt));
        $this->assertSame($receipt,$svc->receive(
            $witness,$company->id,$hall->id,$loan,$receipt,$first,$pdf()));
        try {
            $svc->receive($witness,$company->id,$hall->id,$loan,$receipt,
                [$items[0]['itemId']=>2],$pdf());
            $this->fail('Immutable outside partner physical count replay changed.');
        }catch(ConflictHttpException){}
        try {
            $svc->receive($witness,$company->id,$other->id,$loan,(string)Str::uuid(),
                $first,$pdf());
            $this->fail('Foreign-hall return was allowed.');
        }catch(ConflictHttpException){}
        try {
            $svc->receive($witness,$company->id,$hall->id,$loan,(string)Str::uuid(),
                [$items[0]['itemId']=>4],$pdf());
            $this->fail('Cumulative outside physical return exceeded original 6 units.');
        }catch(ConflictHttpException){}
        foreach($items as $item){
            $p=DB::table('hall_inventory_positions')->where('company_id',$company->id)
                ->where('hall_id',$hall->id)->where('item_id',$item['itemId'])->first();
            $this->assertSame($item['quantity'],(int)$p->owned_on_loan);
        }
        $wrong=[$items[0]['itemId']=>['good'=>2,'damaged'=>0,'unresolved'=>0],
            $items[1]['itemId']=>['good'=>0,'damaged'=>0,'unresolved'=>1]];
        $this->be($owner);
        try{
            $svc->review($owner,$company->id,$hall->id,$receipt,(string)Str::uuid(),$wrong);
            $this->fail('Owner ignored missing one unit from physical count.');
        }catch(\InvalidArgumentException){}
        $decisions=[$items[0]['itemId']=>['good'=>2,'damaged'=>1,'unresolved'=>0],
            $items[1]['itemId']=>['good'=>0,'damaged'=>0,'unresolved'=>1]];
        $this->be($witness);
        try {
            $svc->review($witness,$company->id,$hall->id,$receipt,(string)Str::uuid(),$decisions);
            $this->fail('Physical witness independently approved their own return.');
        }catch(AuthorizationException){}
        $this->be($owner);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('Independently assess outside partner physical return')
            ->call('startExternalOutReturnReview',$receipt)->assertHasNoErrors()
            ->set('externalOutReviewGood.'.$items[0]['itemId'],'2')
            ->set('externalOutReviewDamaged.'.$items[0]['itemId'],'1')
            ->set('externalOutReviewUnresolved.'.$items[0]['itemId'],'0')
            ->set('externalOutReviewGood.'.$items[1]['itemId'],'0')
            ->set('externalOutReviewDamaged.'.$items[1]['itemId'],'0')
            ->set('externalOutReviewUnresolved.'.$items[1]['itemId'],'1')
            ->call('submitExternalOutReturnReview')->assertHasNoErrors()
            ->assertSee('INDEPENDENT PHYSICAL CONDITION REVIEWED')
            ->assertSee('unresolved stays ON LOAN');
        $stored=DB::table('hall_inventory_external_out_return_reviews')
            ->where('company_id',$company->id)->where('receipt_id',$receipt)->first();
        $this->assertNotNull($stored);
        $this->assertSame($stored->id,
            $svc->review($owner,$company->id,$hall->id,$receipt,$stored->id,$decisions));
        try {
            $svc->review($owner,$company->id,$hall->id,
                $receipt,(string)Str::uuid(),$decisions);
            $this->fail('Repeat review minted another decision.');
        }catch(ConflictHttpException){}
        foreach($items as $i=>$item) {
            $p=DB::table('hall_inventory_positions')->where('company_id',$company->id)
                ->where('hall_id',$hall->id)->where('item_id',$item['itemId'])->first();
            $this->assertSame(($i===0?3:4),(int)$p->owned_on_loan);
            $this->assertSame(($i===0?8:6),(int)$p->owned_usable);
            $this->assertSame(($i===0?1:0),(int)$p->owned_damaged);
            $this->assertSame(0,(int)$p->borrowed_usable);
        }
        $this->assertSame(2,DB::table('hall_inventory_external_out_return_review_lines')->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->whereIn('event_kind',['return_owned_good','return_owned_damaged'])->count());
        // PostgreSQL provenance rule prevents a mismatched condition line,
        // even when an attacker uses a valid existing same-hall item.
        try {
            DB::transaction(function()use($company,$hall,$stored,$receipt,$items):void{
                DB::table('hall_inventory_external_out_return_review_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                    'review_id'=>$stored->id,'receipt_id'=>$receipt,
                    'hall_id'=>$hall->id,'item_id'=>$items[0]['itemId'],
                    'good_quantity'=>1,'damaged_quantity'=>0,'unresolved_quantity'=>0
                ]);
            });
            $this->fail('Forged or duplicate review count accepted.');
        }catch(QueryException){}
        $this->assertSame(0,DB::table('finance_journals')->count());
        // Second physical return batch with a separate independent review.
        $this->be($witness);
        $finalQty=[$items[0]['itemId']=>3,$items[1]['itemId']=>3];
        $second=(string)Str::uuid();
        $svc->receive($witness,$company->id,$hall->id,$loan,$second,$finalQty,$pdf());
        $this->be($owner);
        $secondReview=(string)Str::uuid();
        $svc->review($owner,$company->id,$hall->id,$second,$secondReview,[
            $items[0]['itemId']=>['good'=>3,'damaged'=>0,'unresolved'=>0],
            $items[1]['itemId']=>['good'=>3,'damaged'=>0,'unresolved'=>0]]);
        $p0=DB::table('hall_inventory_positions')->where('item_id',$items[0]['itemId'])->first();
        $p1=DB::table('hall_inventory_positions')->where('item_id',$items[1]['itemId'])->first();
        $this->assertSame(0,(int)$p0->owned_on_loan); // all first-item quantities physically resolved
        $this->assertSame(1,(int)$p1->owned_on_loan); // unresolved one still on loan
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(2,DB::table('hall_inventory_external_out_return_reviews')->count());
    }
}