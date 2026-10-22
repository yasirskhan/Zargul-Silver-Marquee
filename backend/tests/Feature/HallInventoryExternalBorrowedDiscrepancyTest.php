<?php
namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryExternalBorrowedDiscrepancyService;
use App\Services\HallInventoryExternalBorrowedIntakeService;
use App\Services\HallInventoryExternalBorrowedReturnService;
use App\Services\HallInventoryLoanDraftReview;
use App\Services\HallInventoryLoanDraftService;
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

final class HallInventoryExternalBorrowedDiscrepancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_short_damaged_counts_are_immutable_evidence_without_stock_money_or_closure():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Partner Discrepancy Regression',
            'slug'=>'partner-discrepancy-regression']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Partner Discrepancy Hall',
            'slug'=>'partner-discrepancy-hall','capacity'=>150]);
        $foreign=Hall::create(['company_id'=>$company->id,'name'=>'Foreign Discrepancy Hall',
            'slug'=>'foreign-discrepancy-hall','capacity'=>100]);
        $drafter=User::create(['name'=>'Discrepancy Draft Creator',
            'email'=>'discrepancy-drafter@example.test','password'=>'test-only']);
        $handler=User::create(['name'=>'Discrepancy Return Handler',
            'email'=>'discrepancy-handler@example.test','password'=>'test-only']);
        $owner=User::create(['name'=>'Discrepancy Recording Owner',
            'email'=>'discrepancy-owner@example.test','password'=>'test-only']);
        $reviewer=User::create(['name'=>'Independent Discrepancy Reviewer',
            'email'=>'discrepancy-reviewer@example.test','password'=>'test-only']);
        foreach([[$drafter,'manager'],[$handler,'manager'],[$owner,'owner'],[$reviewer,'owner']] as [$u,$role]) {
            $u->markEmailAsVerified();
            $m=CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'hall_id'=>$hall->id,'membership_id'=>$m->id
            ]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach(['catalog_enabled','loan_drafts_enabled','external_borrowed_intake_enabled',
            'external_borrowed_return_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);

        $this->be($drafter);
        $catalog=app(HallInventoryCatalogService::class);
        $requested=[];
        foreach([['Supplier staging panels',10],['Supplier cable reels',6]] as [$name,$qty]) {
            $item=(string)Str::uuid();
            $catalog->create($drafter,$company->id,$hall->id,$item,$name,
                'Partner discrepancy test catalog','piece',1,'Partner discrepancy fixture');
            $requested[]=['itemId'=>$item,'quantity'=>$qty];
        }
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,$loan,
            'external_in',null,$hall->id,$requested,'2027-12-20',
            'Partner discrepancy independent custody test','Partner Hall','Signed counterparty');
        $signed=static fn(string $name,string $marker)=>UploadedFile::fake()->createWithContent(
            $name,"%PDF-1.4\n".$marker."\n%%EOF");
        $counts=[];
        foreach($requested as $line)$counts[$line['itemId']]=$line['quantity'];
        $this->be($handler);
        $receipt=(string)Str::uuid();
        app(HallInventoryExternalBorrowedIntakeService::class)->record(
            $handler,$company->id,$hall->id,$loan,$receipt,
            $signed('partner-intake.pdf','intake'),$counts);
        $this->be($owner);
        app(HallInventoryExternalBorrowedIntakeService::class)->accept(
            $owner,$company->id,$hall->id,$loan,(string)Str::uuid());

        $returns=app(HallInventoryExternalBorrowedReturnService::class);
        $first=[$requested[0]['itemId']=>6,$requested[1]['itemId']=>4];
        $this->be($handler);
        $batch=(string)Str::uuid();
        $returns->dispatch($handler,$company->id,$hall->id,$loan,$batch,$first,
            $signed('partner-return.pdf','return'));
        $this->assertSame(10,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));

        $discrimination=app(HallInventoryExternalBorrowedDiscrepancyService::class);
        $countsA=[
            $requested[0]['itemId']=>['received'=>5,'damaged'=>1],
            $requested[1]['itemId']=>['received'=>4,'damaged'=>0],
        ];
        try {
            $discrimination->report($owner,$company->id,$hall->id,$batch,
                (string)Str::uuid(),$countsA,'Partner document records one missing and one damaged panel.',
                $signed('partner-short.pdf','short'));
            $this->fail('Partner discrepancy was accepted while its release gate was OFF.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.external_borrowed_discrepancy_enabled',true);

        $this->be($handler);
        try {
            $discrimination->report($handler,$company->id,$hall->id,$batch,
                (string)Str::uuid(),$countsA,'Return dispatcher cannot record their own partner short count.',
                $signed('partner-short.pdf','short'));
            $this->fail('Return dispatcher recorded the partner short count without separation.');
        }catch(AuthorizationException){}

        $this->be($owner);
        try {
            $discrimination->report($owner,$company->id,$foreign->id,$batch,
                (string)Str::uuid(),$countsA,'Foreign hall must not record this partner short count.',
                $signed('partner-short.pdf','short'));
            $this->fail('Foreign hall partner discrepancy was accepted.');
        }catch(ConflictHttpException){}
        $discrepancyId=(string)Str::uuid();
        $this->assertSame($discrepancyId,$discrimination->report($owner,$company->id,$hall->id,
            $batch,$discrepancyId,$countsA,
            'Partner signed five received panels and one damaged, leaving one missing.',
            $signed('partner-short.pdf','short')));
        $this->assertSame(1,DB::table('hall_inventory_external_in_discrepancies')->count());
        $this->assertSame(2,DB::table('hall_inventory_external_in_discrepancy_lines')->count());
        $this->assertSame(10,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('owned_usable'));
        $this->assertSame(0,DB::table('finance_journals')->count());
        $lineA=DB::table('hall_inventory_external_in_discrepancy_lines')
            ->where('discrepancy_id',$discrepancyId)->where('item_id',$requested[0]['itemId'])->first();
        $this->assertSame(6,(int)$lineA->shipped_quantity);
        $this->assertSame(5,(int)$lineA->received_quantity);
        $this->assertSame(1,(int)$lineA->damaged_quantity);

        $this->assertSame($discrepancyId,$discrimination->report($owner,$company->id,$hall->id,
            $batch,$discrepancyId,$countsA,
            'Partner signed five received panels and one damaged, leaving one missing.',
            $signed('partner-short.pdf','short')));
        try {
            $discrimination->report($owner,$company->id,$hall->id,$batch,$discrepancyId,
                [$requested[0]['itemId']=>['received'=>6,'damaged'=>0],
                 $requested[1]['itemId']=>['received'=>4,'damaged'=>0]],
                'Conflicting replay tries to erase the partner short count entirely.',
                $signed('partner-short.pdf','short'));
            $this->fail('Conflicting partner discrepancy replay changed signed counts.');
        }catch(ConflictHttpException){}
        try {
            $discrimination->report($owner,$company->id,$hall->id,$batch,
                (string)Str::uuid(),[
                    $requested[0]['itemId']=>['received'=>6,'damaged'=>0],
                    $requested[1]['itemId']=>['received'=>4,'damaged'=>0],
                ],'Second record attempts to hide the same partner shortfall entirely.',
                $signed('partner-short.pdf','short'));
            $this->fail('A partner batch accepted a second or no-shortfall discrepancy.');
        }catch(ConflictHttpException){}

        $this->be($owner);
        try {
            $discrimination->decide($owner,$company->id,$hall->id,$discrepancyId,
                (string)Str::uuid(),'substantiated','Partner recorder must not decide their own document.');
            $this->fail('Partner discrepancy recorder self-approved.');
        }catch(AuthorizationException){}
        $this->be($handler);
        try {
            $discrimination->decide($handler,$company->id,$hall->id,$discrepancyId,
                (string)Str::uuid(),'substantiated','Return dispatcher must not decide partner evidence.');
            $this->fail('Return dispatcher independently reviewed their own handover.');
        }catch(AuthorizationException){}
        $this->be($reviewer);
        $decisionId=(string)Str::uuid();
        $this->assertSame($decisionId,$discrimination->decide($reviewer,$company->id,$hall->id,
            $discrepancyId,$decisionId,'substantiated',
            'Independent review confirms the partner signed short and damaged counts.'));
        $this->assertSame($decisionId,$discrimination->decide($reviewer,$company->id,$hall->id,
            $discrepancyId,$decisionId,'substantiated',
            'Independent review confirms the partner signed short and damaged counts.'));
        try {
            $discrimination->decide($reviewer,$company->id,$hall->id,$discrepancyId,
                (string)Str::uuid(),'rejected','Conflicting replay attempts to reverse the substantiated finding.');
            $this->fail('Partner discrepancy decision was mutable through replay.');
        }catch(ConflictHttpException){}
        $this->assertSame(10,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        $this->assertSame(0,DB::table('finance_journals')->count());

        $read=collect(app(HallInventoryLoanDraftReview::class)
            ->recent($reviewer,$company->id,$hall->id))->firstWhere('id',$loan);
        $this->assertNotNull($read);
        $custody=$read['externalReturnReconciliation'];
        $this->assertTrue($custody['partnerDiscrepancyExists']);
        $this->assertFalse($custody['partnerDiscrepancyPending']);
        $this->assertSame(10,$custody['partnerReportedShipped']);
        $this->assertSame(9,$custody['partnerReportedReceived']);
        $this->assertSame(1,$custody['partnerReportedDamaged']);
        $this->assertSame(1,$custody['partnerReportedMissing']);
        $this->assertSame(0,$custody['partnerConfirmedQuantity']);
        $this->assertFalse($custody['allPhysicalReturnsConfirmed']);
        $this->assertSame(10,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));

        $this->be($owner);
        try {
            $returns->confirm($owner,$company->id,$hall->id,$batch,(string)Str::uuid(),
                $first,$signed('partner-full.pdf','full'));
            $this->fail('Signed partner shortfall was overwritten by an exact receipt confirmation.');
        }catch(ConflictHttpException){}

        try {
            DB::transaction(function()use($discrepancyId):void {
                DB::table('hall_inventory_external_in_discrepancies')
                    ->where('id',$discrepancyId)->delete();
            });
            $this->fail('Immutable partner discrepancy evidence was deletable.');
        }catch(QueryException){}
        $this->assertSame(1,DB::table('hall_inventory_external_in_discrepancies')->count());

        // Livewire owner workflow on a later batch of the same original loan.
        $second=[$requested[0]['itemId']=>2,$requested[1]['itemId']=>2];
        $this->be($handler);
        $secondBatch=(string)Str::uuid();
        $returns->dispatch($handler,$company->id,$hall->id,$loan,$secondBatch,$second,
            $signed('partner-return-2.pdf','return-2'));
        $this->be($owner);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Record partner short/damaged count')
            ->call('startExternalDiscrepancy',$secondBatch)->assertHasNoErrors()
            ->set('externalDiscrepancyReceived.'.$requested[0]['itemId'],'1')
            ->set('externalDiscrepancyDamaged.'.$requested[0]['itemId'],'0')
            ->set('externalDiscrepancyReceived.'.$requested[1]['itemId'],'2')
            ->set('externalDiscrepancyDamaged.'.$requested[1]['itemId'],'0')
            ->set('externalDiscrepancyReason','Partner signed only one of two panels on the second return batch.')
            ->set('externalDiscrepancyFile',$signed('partner-short-2.pdf','short-2'))
            ->call('submitExternalDiscrepancy')->assertHasNoErrors()
            ->assertSee('PARTNER-SIGNED SHORT/DAMAGED COUNT — INDEPENDENT REVIEW PENDING');
        $secondDiscrepancy=(string)DB::table('hall_inventory_external_in_discrepancies')
            ->where('batch_id',$secondBatch)->value('id');
        $this->assertTrue(Str::isUuid($secondDiscrepancy));
        $this->be($owner);
        try {
            $discrimination->decide($owner,$company->id,$hall->id,$secondDiscrepancy,
                (string)Str::uuid(),'substantiated','Original recorder cannot approve the second document.');
            $this->fail('Second partner discrepancy was self-reviewed.');
        }catch(AuthorizationException){}
        $this->be($reviewer);
        $pendingRead=collect(app(HallInventoryLoanDraftReview::class)
            ->recent($reviewer,$company->id,$hall->id))->firstWhere('id',$loan);
        $this->assertTrue($pendingRead['externalReturnReconciliation']['partnerDiscrepancyPending']);
        $this->assertSame(10,$pendingRead['externalReturnReconciliation']['partnerReportedShipped']);
        $this->assertSame(9,$pendingRead['externalReturnReconciliation']['partnerReportedReceived']);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Independently review partner discrepancy')
            ->call('startExternalDiscrepancyReview',$secondDiscrepancy)->assertHasNoErrors()
            ->set('externalDiscrepancyDecision','substantiated')
            ->set('externalDiscrepancyDecisionReason','Partner signature independently confirms the one-unit shortfall.')
            ->call('submitExternalDiscrepancyReview')->assertHasNoErrors()
            ->assertSee('PARTNER-SIGNED SHORT/DAMAGED COUNT — SUBSTANTIATED')
            ->assertSee('Partner-signed short/damaged evidence');
        $this->assertSame(2,DB::table('hall_inventory_external_in_discrepancy_decisions')->count());
        $read=collect(app(HallInventoryLoanDraftReview::class)
            ->recent($reviewer,$company->id,$hall->id))->firstWhere('id',$loan);
        $custody=$read['externalReturnReconciliation'];
        $this->assertSame(14,$custody['partnerReportedShipped']);
        $this->assertSame(12,$custody['partnerReportedReceived']);
        $this->assertSame(1,$custody['partnerReportedDamaged']);
        $this->assertSame(2,$custody['partnerReportedMissing']);
        $this->assertFalse($custody['partnerDiscrepancyPending']);
        $this->assertSame(0,$custody['partnerConfirmedQuantity']);
        $this->assertFalse($custody['allPhysicalReturnsConfirmed']);
        $this->assertSame(14,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('owned_usable'));
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_external_in_clean_closures')->count());
    }
}
