<?php
namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryExternalBorrowedDiscrepancyClearanceService;
use App\Services\HallInventoryExternalInLossObligationService;
use App\Services\HallInventoryExternalInLossSettlementService;
use App\Services\HallInventoryExternalInNoncleanReadinessService;
use App\Services\HallInventoryExternalInNoncleanClosureService;
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
        $approver=User::create(['name'=>'Independent Obligation Approver',
            'email'=>'obligation-approver@example.test','password'=>'test-only']);
        foreach([[$drafter,'manager'],[$handler,'manager'],[$owner,'owner'],
            [$reviewer,'owner'],[$approver,'owner']] as [$u,$role]) {
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
        $this->assertSame(14,$pendingRead['externalReturnReconciliation']['partnerReportedShipped']);
        $this->assertSame(12,$pendingRead['externalReturnReconciliation']['partnerReportedReceived']);
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

        config()->set('inventory_controls.external_borrowed_discrepancy_clearance_enabled',true);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Post partner-confirmed receipt')
            ->call('clearExternalDiscrepancy',$discrepancyId)->assertHasNoErrors()
            ->assertSee('PARTNER-CONFIRMED RECEIPT POSTED');
        $this->assertSame(5,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));

        $clearance=app(HallInventoryExternalBorrowedDiscrepancyClearanceService::class);
        $this->be($owner);
        try {
            $clearance->clear($owner,$company->id,$hall->id,$secondDiscrepancy,(string)Str::uuid());
            $this->fail('Partner-count recorder posted their own physical clearance.');
        }catch(AuthorizationException){}
        $this->be($handler);
        try {
            $clearance->clear($handler,$company->id,$hall->id,$secondDiscrepancy,(string)Str::uuid());
            $this->fail('Return dispatcher posted the partner receipt clearance.');
        }catch(AuthorizationException){}
        $this->be($reviewer);
        $secondClear=(string)Str::uuid();
        $this->assertSame($secondClear,$clearance->clear($reviewer,$company->id,$hall->id,
            $secondDiscrepancy,$secondClear));
        $this->assertSame($secondClear,$clearance->clear($reviewer,$company->id,$hall->id,
            $secondDiscrepancy,$secondClear));
        try {
            $clearance->clear($reviewer,$company->id,$hall->id,
                $secondDiscrepancy,(string)Str::uuid());
            $this->fail('Partner receipt clearance was mutable through a second identifier.');
        }catch(ConflictHttpException){}
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('owned_usable'));
        $this->assertSame(0,DB::table('finance_journals')->count());
        $read=collect(app(HallInventoryLoanDraftReview::class)
            ->recent($reviewer,$company->id,$hall->id))->firstWhere('id',$loan);
        $custody=$read['externalReturnReconciliation'];
        $this->assertSame(12,$custody['partnerClearedQuantity']);
        $this->assertSame(0,$custody['partnerConfirmedQuantity']);
        $this->assertSame(2,$custody['awaitingPartnerConfirmation']);
        $this->assertSame(4,$custody['stillOutstanding']);
        $this->assertSame(2,$custody['partnerReportedMissing']);
        $this->assertFalse($custody['allPhysicalReturnsConfirmed']);
        $this->assertSame(0,DB::table('hall_inventory_external_in_clean_closures')->count());

        config()->set('inventory_controls.external_in_loss_obligation_enabled',true);
        $obligations=app(HallInventoryExternalInLossObligationService::class);
        $this->be($reviewer);
        $firstObligation=(string)Str::uuid();
        $this->assertSame($firstObligation,$obligations->propose($reviewer,$company->id,$hall->id,
            $discrepancyId,$firstObligation,'PKR',50000,
            'Partner-signed one missing and one damaged panel grounds this provisional obligation.'));
        $row=DB::table('hall_inventory_external_in_loss_obligations')
            ->where('id',$firstObligation)->first();
        $this->assertSame(2,(int)$row->quantity_basis);
        $this->assertSame(50000,(int)$row->amount_minor);
        $this->assertSame('PKR',$row->currency);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        try {
            $obligations->decide($reviewer,$company->id,$hall->id,$firstObligation,
                (string)Str::uuid(),'approved','Proposer must not approve their own obligation.');
            $this->fail('External-in obligation proposer self-approved.');
        }catch(AuthorizationException){}
        $this->be($owner);
        try {
            $obligations->decide($owner,$company->id,$hall->id,$firstObligation,
                (string)Str::uuid(),'approved','Discrepancy recorder must not approve the obligation.');
            $this->fail('Discrepancy recorder approved the partner obligation.');
        }catch(AuthorizationException){}
        $this->be($approver);
        $obligationDecision=(string)Str::uuid();
        $this->assertSame($obligationDecision,$obligations->decide($approver,$company->id,$hall->id,
            $firstObligation,$obligationDecision,'approved',
            'Independent owner accepts the provisional partner loss position without payment.'));
        $this->assertSame($obligationDecision,$obligations->decide($approver,$company->id,$hall->id,
            $firstObligation,$obligationDecision,'approved',
            'Independent owner accepts the provisional partner loss position without payment.'));
        try {
            $obligations->decide($approver,$company->id,$hall->id,$firstObligation,
                (string)Str::uuid(),'rejected','Conflicting replay attempts to reverse the approved obligation.');
            $this->fail('External-in obligation decision was mutable through replay.');
        }catch(ConflictHttpException){}
        $history=collect($obligations->recent($approver,$company->id,$hall->id))
            ->firstWhere('id',$firstObligation);
        $this->assertNotNull($history);
        $this->assertSame('approved',$history['decision']);
        $this->assertSame(2,$history['quantityBasis']);
        $this->assertSame(50000,$history['amountMinor']);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('owned_usable'));
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        try {
            DB::transaction(function()use($firstObligation):void {
                DB::table('hall_inventory_external_in_loss_obligations')
                    ->where('id',$firstObligation)->delete();
            });
            $this->fail('Provisional external-in obligation was deletable.');
        }catch(QueryException){}
        $this->assertSame(1,DB::table('hall_inventory_external_in_loss_obligations')->count());
        $this->assertSame(0,DB::table('hall_inventory_external_in_clean_closures')->count());

        $this->be($reviewer);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Propose provisional partner loss obligation')
            ->call('startExternalObligation',$secondDiscrepancy)->assertHasNoErrors()
            ->set('externalObligationCurrency','PKR')
            ->set('externalObligationAmount','25000')
            ->set('externalObligationReason','Partner-signed second batch missing unit grounds this provisional obligation.')
            ->call('submitExternalObligation')->assertHasNoErrors()
            ->assertSee('PROVISIONAL PARTNER LOSS OBLIGATION');
        $secondObligation=DB::table('hall_inventory_external_in_loss_obligations')
            ->where('discrepancy_id',$secondDiscrepancy)->first();
        $this->assertNotNull($secondObligation);
        $this->assertSame(1,(int)$secondObligation->quantity_basis);
        $this->assertSame(25000,(int)$secondObligation->amount_minor);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->call('startExternalObligationReview',$secondObligation->id)->assertForbidden();
        $this->be($approver);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Independently review partner loss obligation')
            ->call('startExternalObligationReview',$secondObligation->id)->assertHasNoErrors()
            ->set('externalObligationDecision','approved')
            ->set('externalObligationDecisionReason','Independent owner accepts the provisional second-batch position.')
            ->call('submitExternalObligationReview')->assertHasNoErrors()
            ->assertSee('basis 1 units — APPROVED');

        config()->set('inventory_controls.external_in_loss_settlement_enabled',true);
        $settlements=app(HallInventoryExternalInLossSettlementService::class);
        $payment=static fn()=>UploadedFile::fake()->createWithContent(
            'partner-payment.pdf',"%PDF-1.4\nPartner bank transfer receipt\n%%EOF");
        $this->be($reviewer);
        try {
            $settlements->record($reviewer,$company->id,$hall->id,$firstObligation,
                (string)Str::uuid(),60000,'PKR','bank','BANK-OVER','2026-10-22',$payment());
            $this->fail('Payment evidence exceeded the independently approved obligation.');
        }catch(ConflictHttpException){}
        try {
            $settlements->record($reviewer,$company->id,$hall->id,$firstObligation,
                (string)Str::uuid(),40000,'USD','bank','BANK-CURRENCY','2026-10-22',$payment());
            $this->fail('Payment evidence accepted a currency different from the obligation.');
        }catch(ConflictHttpException){}
        $settlementId=(string)Str::uuid();
        $this->assertSame($settlementId,$settlements->record($reviewer,$company->id,$hall->id,
            $firstObligation,$settlementId,40000,'PKR','bank','BANK-2026-001',
            '2026-10-22',$payment()));
        $row=DB::table('hall_inventory_external_in_loss_settlements')
            ->where('id',$settlementId)->first();
        $this->assertSame(40000,(int)$row->amount_minor);
        $this->assertSame('PKR',$row->currency);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        try {
            $settlements->review($reviewer,$company->id,$hall->id,$settlementId,
                (string)Str::uuid(),'verified','Payment recorder cannot verify their own evidence.');
            $this->fail('Partner payment recorder self-verified.');
        }catch(AuthorizationException){}
        $this->be($approver);
        $reviewId=(string)Str::uuid();
        $this->assertSame($reviewId,$settlements->review($approver,$company->id,$hall->id,
            $settlementId,$reviewId,'verified',
            'Independent owner verified the bank reference and signed partner receipt.'));
        $this->assertSame($reviewId,$settlements->review($approver,$company->id,$hall->id,
            $settlementId,$reviewId,'verified',
            'Independent owner verified the bank reference and signed partner receipt.'));
        try {
            $settlements->review($approver,$company->id,$hall->id,$settlementId,
                (string)Str::uuid(),'rejected','Conflicting replay attempts to reverse the verified payment.');
            $this->fail('Partner payment review was mutable through replay.');
        }catch(ConflictHttpException){}
        $history=collect($settlements->recent($approver,$company->id,$hall->id))
            ->firstWhere('id',$settlementId);
        $this->assertNotNull($history);
        $this->assertSame('verified',$history['decision']);
        $this->assertSame(40000,$history['amountMinor']);
        $this->assertSame('BANK-2026-001',$history['paymentReference']);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('owned_usable'));
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        $this->assertSame(0,DB::table('hall_inventory_external_in_clean_closures')->count());

        $this->be($approver);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Record partner payment evidence')
            ->call('startExternalSettlement',$secondObligation->id)->assertHasNoErrors()
            ->set('externalSettlementAmount','25000')
            ->set('externalSettlementMethod','bank')
            ->set('externalSettlementReference','BANK-2026-002')
            ->set('externalSettlementPaidOn','2026-10-22')
            ->set('externalSettlementFile',UploadedFile::fake()->createWithContent(
                'partner-payment-2.pdf',"%PDF-1.4\nPartner second payment receipt\n%%EOF"))
            ->call('submitExternalSettlement')->assertHasNoErrors()
            ->assertSee('PARTNER PAYMENT EVIDENCE');
        $secondSettlement=DB::table('hall_inventory_external_in_loss_settlements')
            ->where('obligation_id',$secondObligation->id)->first();
        $this->assertNotNull($secondSettlement);
        $this->assertSame(25000,(int)$secondSettlement->amount_minor);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->call('startExternalSettlementReview',$secondSettlement->id)->assertForbidden();
        config()->set('finance_controls.posting_enabled',true);
        $this->be($owner);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Independently verify partner payment evidence')
            ->call('startExternalSettlementReview',$secondSettlement->id)->assertHasNoErrors()
            ->set('externalSettlementReviewDecision','verified')
            ->set('externalSettlementReviewReason','Independent owner verified the second payment reference and signed receipt.')
            ->call('submitExternalSettlementReview')->assertHasNoErrors()
            ->assertSee('BANK-2026-002');
        $journal=DB::table('finance_journals')->where('company_id',$company->id)
            ->where('entry_type','inventory_partner_payment')->first();
        $this->assertNotNull($journal);
        $this->assertSame(25000,(int)$journal->amount_minor);
        $this->assertSame('PKR',$journal->currency_code);
        $journalLines=DB::table('finance_journal_lines')->where('journal_id',$journal->id)->get();
        $this->assertSame(2,$journalLines->count());
        $this->assertSame('inventory_partner_loss',
            $journalLines->firstWhere('side','debit')->account_code);
        $this->assertSame('bank_clearing',
            $journalLines->firstWhere('side','credit')->account_code);
        $this->assertSame(1,DB::table('finance_journals')->count());

        $this->be($reviewer);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Reverse verified partner payment')
            ->call('startExternalSettlementReversal',$secondSettlement->id)->assertHasNoErrors()
            ->set('externalSettlementReversalReason','Independent correction after bank reversal of the second payment.')
            ->call('submitExternalSettlementReversal')->assertHasNoErrors()
            ->assertSee('LEDGER REVERSED');
        $this->assertSame(2,DB::table('finance_journals')->count());
        $reversal=DB::table('finance_journals')->where('entry_type','reversal')->first();
        $this->assertNotNull($reversal);
        $this->assertSame($journal->id,$reversal->reversal_of);
        $reversalLines=DB::table('finance_journal_lines')->where('journal_id',$reversal->id)->get();
        $this->assertSame('bank_clearing',
            $reversalLines->firstWhere('side','debit')->account_code);
        $this->assertSame('inventory_partner_loss',
            $reversalLines->firstWhere('side','credit')->account_code);
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')->sum('owned_usable'));
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')->sum('borrowed_transit'));
        $this->assertSame(0,DB::table('hall_inventory_external_in_clean_closures')->count());

        $this->be($approver);
        $readiness=app(HallInventoryExternalInNoncleanReadinessService::class)
            ->assess($approver,$company->id,$hall->id,$loan);
        $this->assertFalse($readiness['ready']);
        $this->assertSame(2,$readiness['physicalUnitsAtHall']);
        $this->assertContains('Physical borrowed units remain in the hall.',
            $readiness['blockers']);
        $this->assertSame(2,$readiness['missingInTransit']);
        $this->assertSame(2,$readiness['partnerReportedMissing']);
        $this->assertSame(0,$readiness['pendingDiscrepancies']);
        $this->assertSame(2,$readiness['unsettledObligations']);
        $this->assertFalse($readiness['verifiedLedgerPosted']);
        $this->assertNotEmpty($readiness['blockers']);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->assertSee('Check non-clean closure readiness')
            ->call('checkExternalInClosureReadiness',$loan)->assertHasNoErrors()
            ->assertSee('NON-CLEAN CLOSURE READINESS: BLOCKED');

        config()->set('inventory_controls.external_in_nonclean_closure_enabled',true);
        $this->be($drafter);
        $closureItem=(string)Str::uuid();
        $catalog->create($drafter,$company->id,$hall->id,$closureItem,'Closure test display',
            'Closure test catalog','piece',1,'Non-clean closure fixture');
        $closureLoan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,$closureLoan,
            'external_in',null,$hall->id,[['itemId'=>$closureItem,'quantity'=>5]],
            '2027-12-31','Non-clean closure fixture','Closure Partner','Signed counterparty');
        $this->be($handler);
        $closureReceipt=(string)Str::uuid();
        app(HallInventoryExternalBorrowedIntakeService::class)->record(
            $handler,$company->id,$hall->id,$closureLoan,$closureReceipt,
            $signed('closure-intake.pdf','closure-intake'),[$closureItem=>5]);
        $this->be($owner);
        app(HallInventoryExternalBorrowedIntakeService::class)->accept(
            $owner,$company->id,$hall->id,$closureLoan,(string)Str::uuid());
        $this->be($handler);
        $closureBatch=(string)Str::uuid();
        $returns->dispatch($handler,$company->id,$hall->id,$closureLoan,$closureBatch,
            [$closureItem=>5],$signed('closure-return.pdf','closure-return'));
        $this->be($owner);
        $closureDiscrepancy=(string)Str::uuid();
        $discrimination->report($owner,$company->id,$hall->id,$closureBatch,$closureDiscrepancy,
            [$closureItem=>['received'=>4,'damaged'=>0]],
            'Partner received four of five closure test units.',
            $signed('closure-short.pdf','closure-short'));
        $this->be($reviewer);
        $discrimination->decide($reviewer,$company->id,$hall->id,$closureDiscrepancy,
            (string)Str::uuid(),'substantiated','Independent review confirms the closure shortfall.');
        $clearance->clear($reviewer,$company->id,$hall->id,$closureDiscrepancy,
            (string)Str::uuid());
        $closureObligation=(string)Str::uuid();
        $obligations->propose($reviewer,$company->id,$hall->id,$closureDiscrepancy,
            $closureObligation,'PKR',1000,
            'Settled missing unit grounds the final non-clean closure obligation.');
        $this->be($approver);
        $obligations->decide($approver,$company->id,$hall->id,$closureObligation,
            (string)Str::uuid(),'approved','Independent approval of the final closure obligation.');
        $this->be($reviewer);
        $closureSettlement=(string)Str::uuid();
        $settlements->record($reviewer,$company->id,$hall->id,$closureObligation,
            $closureSettlement,1000,'PKR','bank','BANK-CLOSURE-1','2026-10-22',
            UploadedFile::fake()->createWithContent('closure-payment.pdf',
                "%PDF-1.4\nclosure payment\n%%EOF"));
        $this->be($approver);
        $settlements->review($approver,$company->id,$hall->id,$closureSettlement,
            (string)Str::uuid(),'verified',
            'Independent verification of the final closure payment.');
        // Another genuine external-in original loan shares this exact
        // hall/catalog position. Both loans have their own signed short return,
        // approved obligation and independently verified partner settlement.
        $sharedLoan=(string)Str::uuid();
        $this->be($drafter);
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,$sharedLoan,
            'external_in',null,$hall->id,[['itemId'=>$closureItem,'quantity'=>3]],
            '2027-12-31','Second original loan same physical catalog','Second Partner',
            'Separately signed counterparty');
        $this->be($handler);
        app(HallInventoryExternalBorrowedIntakeService::class)->record(
            $handler,$company->id,$hall->id,$sharedLoan,(string)Str::uuid(),
            $signed('second-intake.pdf','second-intake'),[$closureItem=>3]);
        $this->be($owner);
        app(HallInventoryExternalBorrowedIntakeService::class)->accept(
            $owner,$company->id,$hall->id,$sharedLoan,(string)Str::uuid());
        $this->be($handler);
        $sharedBatch=(string)Str::uuid();
        $returns->dispatch($handler,$company->id,$hall->id,$sharedLoan,$sharedBatch,
            [$closureItem=>3],$signed('second-return.pdf','second-return'));
        $this->be($owner);
        $sharedDiscrepancy=(string)Str::uuid();
        $discrimination->report($owner,$company->id,$hall->id,$sharedBatch,$sharedDiscrepancy,
            [$closureItem=>['received'=>2,'damaged'=>0]],
            'Partner independently received two of three units on second loan.',
            $signed('second-short.pdf','second-short'));
        $this->be($reviewer);
        $discrimination->decide($reviewer,$company->id,$hall->id,$sharedDiscrepancy,
            (string)Str::uuid(),'substantiated','Second loan signed independent shortfall.');
        $clearance->clear($reviewer,$company->id,$hall->id,$sharedDiscrepancy,
            (string)Str::uuid());
        $sharedObligation=(string)Str::uuid();
        $obligations->propose($reviewer,$company->id,$hall->id,$sharedDiscrepancy,
            $sharedObligation,'PKR',1000,'Independent second original loss obligation.');
        $this->be($approver);
        $obligations->decide($approver,$company->id,$hall->id,$sharedObligation,
            (string)Str::uuid(),'approved','Second independent partner obligation approval.');
        $this->be($reviewer);
        $sharedSettlement=(string)Str::uuid();
        $settlements->record($reviewer,$company->id,$hall->id,$sharedObligation,
            $sharedSettlement,1000,'PKR','bank','BANK-CLOSURE-SECOND','2026-10-22',
            UploadedFile::fake()->createWithContent('second-payment.pdf',
                "%PDF-1.4\nsecond payment\n%%EOF"));
        $this->be($approver);
        $settlements->review($approver,$company->id,$hall->id,$sharedSettlement,
            (string)Str::uuid(),'verified','Second partner evidence independently verified.');
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')
            ->where('item_id',$closureItem)->sum('borrowed_transit'));
        $ready=app(HallInventoryExternalInNoncleanReadinessService::class)
            ->assess($approver,$company->id,$hall->id,$closureLoan);
        $this->assertTrue($ready['ready']);
        Livewire::test(HallInventory::class)
            ->set('hallId',$hall->id)
            ->call('checkExternalInClosureReadiness',$closureLoan)
            ->assertSee('NON-CLEAN CLOSURE READINESS: READY')
            ->assertSee('Issue non-clean final closure')
            ->call('issueExternalInNoncleanClosure',$closureLoan)->assertHasNoErrors()
            ->assertSee('EXTERNAL BORROWED NON-CLEAN FINAL CLOSURE ENCL-1');
        $closureRow=DB::table('hall_inventory_external_in_nonclean_closures')
            ->where('loan_id',$closureLoan)->first();
        $this->assertNotNull($closureRow);
        $this->assertSame(1,(int)$closureRow->missing_quantity);
        $this->assertSame(1000,(int)$closureRow->amount_minor);
        $this->assertSame(1,(int)DB::table('hall_inventory_positions')
            ->where('item_id',$closureItem)->sum('borrowed_transit'));
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('item_id',$closureItem)->sum('owned_usable'));
        $pdf=Storage::disk('local')->get('private/hall-external-in-nonclean/'.$company->id.
            '/'.$closureLoan.'/'.$closureRow->id.'.pdf');
        $this->assertStringStartsWith('%PDF-1.4',$pdf);
        $this->assertSame(hash('sha256',$pdf),$closureRow->pdf_sha256);
        $closureService=app(HallInventoryExternalInNoncleanClosureService::class);
        $this->assertSame('ENCL-1',$closureService->close($approver,$company->id,$hall->id,
            $closureLoan,(string)$closureRow->id)['serialNumber']);
        try {
            $closureService->close($approver,$company->id,$hall->id,
                $closureLoan,(string)Str::uuid());
            $this->fail('Non-clean closure was mutable through a second identifier.');
        }catch(ConflictHttpException){}
        $journalsBefore=DB::table('finance_journals')->count();
        $sharedClosureId=(string)Str::uuid();
        $this->assertSame('ENCL-2',$closureService->close($approver,$company->id,$hall->id,
            $sharedLoan,$sharedClosureId)['serialNumber']);
        $this->assertSame('ENCL-2',$closureService->close($approver,$company->id,$hall->id,
            $sharedLoan,$sharedClosureId)['serialNumber']);
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('item_id',$closureItem)->sum('borrowed_transit'));
        $this->assertSame($journalsBefore,DB::table('finance_journals')->count());
        $this->assertSame(2,DB::table('hall_inventory_external_in_nonclean_closures')
            ->where('company_id',$company->id)->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('company_id',$company->id)->where('item_id',$closureItem)
            ->where('event_kind','writeoff_borrowed_transit')->count());
        $auditAction='hall_inventory.external_in_nonclean_certificate_downloaded';
        $beforeDownloads=DB::table('audit_events')->where('company_id',$company->id)
            ->where('action',$auditAction)->count();
        $download=$closureService->download($approver,$company->id,$hall->id,(string)$closureRow->id);
        $this->assertSame($pdf,$download['bytes']);
        $this->assertSame('application/pdf',$download['mime']);
        $this->assertSame($beforeDownloads+1,DB::table('audit_events')
            ->where('company_id',$company->id)->where('action',$auditAction)->count());
        $pdfPath=(string)$closureRow->pdf_path;
        Storage::disk('local')->put($pdfPath,'tampered final certificate');
        try {
            $closureService->download($approver,$company->id,$hall->id,(string)$closureRow->id);
            $this->fail('Tampered final ENCL PDF was downloaded.');
        }catch(ConflictHttpException){}
        Storage::disk('local')->put($pdfPath,$pdf);
        $this->assertSame($beforeDownloads+1,DB::table('audit_events')
            ->where('company_id',$company->id)->where('action',$auditAction)->count());
        CompanyMembership::query()->where('company_id',$company->id)
            ->where('user_id',$approver->id)->update(['is_active'=>false]);
        try {
            $closureService->download($approver,$company->id,$hall->id,(string)$closureRow->id);
            $this->fail('Revoked owner accessed the ENCL private final certificate.');
        }catch(AuthorizationException){}
        $this->assertSame($beforeDownloads+1,DB::table('audit_events')
            ->where('company_id',$company->id)->where('action',$auditAction)->count());
    }
}
