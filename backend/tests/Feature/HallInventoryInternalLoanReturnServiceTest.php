<?php
namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
use Filament\Facades\Filament;
use Livewire\Livewire;
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
use App\Services\HallInventoryInternalLoanReturnReview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryInternalLoanReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_return_batches_preserve_lender_ownership_and_require_source_acknowledgment():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Internal Return Company','slug'=>'internal-return-company']);
        $src=Hall::create(['company_id'=>$company->id,'name'=>'Return Origin','slug'=>'return-origin','capacity'=>100]);
        $dest=Hall::create(['company_id'=>$company->id,'name'=>'Return Destination','slug'=>'return-destination','capacity'=>100]);
        $owner=User::create(['name'=>'Return Draft Owner','email'=>'return-draft-owner@example.test','password'=>'test-only']);
        $dispatcher=User::create(['name'=>'Return Source Dispatcher','email'=>'return-dispatcher@example.test','password'=>'test-only']);
        $receiver=User::create(['name'=>'Return Destination Manager','email'=>'return-receiver@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$dispatcher,'admin'],[$receiver,'manager']] as [$actor,$role]) {
            $actor->markEmailAsVerified();
            $membership=CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$actor->id,'role'=>$role]);
            if($actor->id===$receiver->id)
                HallAssignment::create(['company_id'=>$company->id,'hall_id'=>$dest->id,
                    'membership_id'=>$membership->id]);
        }
        $this->be($owner);
        $catalog=app(HallInventoryCatalogService::class);
        $srcItem=(string)Str::uuid();$dstItem=(string)Str::uuid();
        $catalog->create($owner,$company->id,$src->id,$srcItem,
            'Return serving trays','Furniture','piece',2,'Source return catalog');
        $catalog->create($owner,$company->id,$dest->id,$dstItem,
            'Return serving trays','Furniture','piece',2,'Destination return catalog');
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,
            'hall_id'=>$src->id,'item_id'=>$srcItem,'owned_usable'=>30,
            'revision'=>0,'created_at'=>now(),'updated_at'=>now()
        ]);
        config()->set('inventory_controls.loan_drafts_enabled',true);
        config()->set('inventory_controls.loan_dispatch_enabled',true);
        config()->set('inventory_controls.loan_receipt_enabled',true);
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,$loan,
            'internal',$src->id,$dest->id,[['itemId'=>$srcItem,'quantity'=>12]],
            '2027-12-30','Partial multi-batch return receipt');
        $pdf=fn()=>UploadedFile::fake()->createWithContent('handover.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->be($dispatcher);
        app(HallInventoryInternalLoanDispatchService::class)->dispatch(
            $dispatcher,$company->id,$loan,(string)Str::uuid(),[$srcItem=>0],$pdf());
        $this->be($receiver);
        app(HallInventoryInternalLoanReceiptService::class)->receive(
            $receiver,$company->id,$loan,(string)Str::uuid(),
            [$srcItem=>$dstItem],[$dstItem=>0],$pdf());

        config()->set('inventory_controls.catalog_enabled',true);
        config()->set('inventory_controls.loan_returns_enabled',true);
        $signed=app(\App\Services\HallInventoryInternalDiscrepancyService::class);
        $statementId=(string)Str::uuid();
        try {
            $signed->submit($receiver,$company->id,$dest->id,$loan,$srcItem,$statementId,
                'missing',2,'Signed receiving-hall count discrepancy observed.',$pdf());
            $this->fail('Unreleased signed discrepancy allowed.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.internal_discrepancy_enabled',true);
        $this->assertSame($statementId,$signed->submit($receiver,$company->id,
            $dest->id,$loan,$srcItem,$statementId,'missing',2,
            'Signed receiving-hall count discrepancy observed.',$pdf()));
        $this->assertSame($statementId,$signed->submit($receiver,$company->id,
            $dest->id,$loan,$srcItem,$statementId,'missing',2,
            'Signed receiving-hall count discrepancy observed.',$pdf()));
        try {
            $signed->submit($receiver,$company->id,$dest->id,$loan,$srcItem,$statementId,
                'missing',3,'Changed signed receiving count must fail replay.',$pdf());
            $this->fail('Conflicting borrower evidence retry accepted.');
        }catch(ConflictHttpException){}
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$dest->id)->call('startInternalDiscrepancy',$loan)
            ->assertHasNoErrors()->assertSee('Signed internal discrepancy evidence')
            ->assertSee('SOURCE REVIEW PENDING');
        $this->be($owner);
        try {
            $signed->submit($owner,$company->id,$src->id,$loan,$srcItem,(string)Str::uuid(),
                'missing',1,'Source owner must not sign borrower physical evidence.',$pdf());
            $this->fail('Source owner signed borrower evidence.');
        }catch(AuthorizationException){}
        $reviewId=(string)Str::uuid();
        $this->assertSame($reviewId,$signed->review($owner,$company->id,$src->id,
            $statementId,$reviewId,'requires_more_evidence',
            'Independent source witness requests corroborating delivery document.'));
        $this->assertSame($reviewId,$signed->review($owner,$company->id,$src->id,
            $statementId,$reviewId,'requires_more_evidence',
            'Independent source witness requests corroborating delivery document.'));
        try {
            $signed->review($owner,$company->id,$src->id,$statementId,(string)Str::uuid(),
                'corroborated','Conflicting immutable borrower review must fail.');
            $this->fail('Source decision overwritten.');
        }catch(ConflictHttpException){}
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$src->id)->call('startInternalDiscrepancyReview',$loan)
            ->assertHasNoErrors()->assertSee('requires_more_evidence');
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(12,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$src->id)->where('item_id',$srcItem)->value('owned_on_loan'));
        $this->be($receiver);
        config()->set('inventory_controls.loan_returns_enabled',false);
        $svc=app(HallInventoryInternalLoanReturnService::class);
        $first=(string)Str::uuid();
        $send=fn(string $id,int $qty,int $revision)=>$svc->send(
            $receiver,$company->id,$loan,$id,[$srcItem=>$qty],
            [$dstItem=>$revision],$pdf());
        try{$send($first,5,1);$this->fail('Return released despite gate OFF.');}
        catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_returns_enabled',true);
        try{$send($first,5,0);$this->fail('Stale destination return revision accepted.');}
        catch(ConflictHttpException){}
        $this->be($owner);
        try{
            $svc->send($owner,$company->id,$loan,$first,
                [$srcItem=>5],[$dstItem=>1],$pdf());
            $this->fail('Originator sent borrower return without independent custody.');
        }catch(AuthorizationException){}
        $this->be($receiver);
        $this->assertSame($first,$send($first,5,1));
        $this->assertSame($first,$send($first,5,1));
        $read=app(HallInventoryInternalLoanReturnReview::class);
        $destinationLines=$read->destinationLines($receiver,$company->id,$dest->id,$loan);
        $this->assertCount(1,$destinationLines);
        $this->assertSame(12,$destinationLines[0]['receivedQuantity']);
        $this->assertSame(5,$destinationLines[0]['alreadyReturnedQuantity']);
        $this->assertSame(7,$destinationLines[0]['returnableQuantity']);
        $this->assertSame(2,$destinationLines[0]['revision']);
        try{
            $read->sourceBatches($receiver,$company->id,$src->id);
            $this->fail('Destination-only manager could read lender return review.');
        }catch(AuthorizationException){}
        $this->be($owner);
        // An independent loan reusing the same catalog item must not
        // multiply or leak this return batch's line snapshots.
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,
            (string)Str::uuid(),'internal',$src->id,$dest->id,
            [['itemId'=>$srcItem,'quantity'=>2]],'2027-11-20',
            'Another loan with matching catalog item');
        $sourceBatches=$read->sourceBatches($owner,$company->id,$src->id);
        $this->assertCount(1,$sourceBatches);
        $this->assertSame('IN_TRANSIT_SOURCE_ACK_PENDING',$sourceBatches[0]['state']);
        $this->assertCount(1,$sourceBatches[0]['lines']);
        $this->be($receiver);
        $this->assertSame(7,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->value('borrowed_usable'));
        $this->assertSame(5,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->value('borrowed_transit'));
        $this->assertSame(12,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$src->id)->where('item_id',$srcItem)->value('owned_on_loan'));
        $second=(string)Str::uuid();
        $this->assertSame($second,$send($second,3,2));
        $this->assertSame(4,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->value('borrowed_usable'));
        $this->assertSame(8,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->value('borrowed_transit'));
        try{$send((string)Str::uuid(),5,3);$this->fail('Borrowed return exceeds original 12.');}
        catch(ConflictHttpException){}
        try{$send($first,6,1);$this->fail('Conflicting immutable return batch retry accepted.');}
        catch(ConflictHttpException){}
        $this->assertSame(2,DB::table('hall_inventory_internal_loan_return_batches')->count());
        $this->assertSame(2,DB::table('hall_inventory_internal_loan_return_lines')->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('event_kind','send_borrowed_back')->count());
        $this->assertSame(2,DB::table('audit_events')
            ->where('action','hall_inventory.internal_loan_partial_return_sent')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$src->id)->where('item_id',$srcItem)->value('owned_damaged'));

        // Source ownership stays ON LOAN until independent arrival/condition review.
        $this->assertSame(12,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$src->id)->where('item_id',$srcItem)->value('owned_on_loan'));
        $acceptor=app(\App\Services\HallInventoryInternalLoanReturnAcceptanceService::class);
        $acceptance=(string)Str::uuid();
        $conditions=[$srcItem=>['good'=>3,'damaged'=>1]];
        $this->be($owner);
        try {
            $acceptor->accept($owner,$company->id,$first,$acceptance,
                $conditions,[$srcItem=>1],[$dstItem=>3],$pdf());
            $this->fail('Unreleased independent return acceptance posted stock.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_return_acceptance_enabled',true);
        try {
            $acceptor->accept($owner,$company->id,$first,$acceptance,
                $conditions,[$srcItem=>0],[$dstItem=>3],$pdf());
            $this->fail('Stale source return acceptance revision accepted.');
        }catch(ConflictHttpException){}
        $this->be($receiver);
        try {
            $acceptor->accept($receiver,$company->id,$first,$acceptance,
                $conditions,[$srcItem=>1],[$dstItem=>3],$pdf());
            $this->fail('Destination return sender self-accepted source stock.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $this->assertSame($acceptance,$acceptor->accept(
            $owner,$company->id,$first,$acceptance,$conditions,
            [$srcItem=>1],[$dstItem=>3],$pdf()));
        $this->assertSame($acceptance,$acceptor->accept(
            $owner,$company->id,$first,$acceptance,$conditions,
            [$srcItem=>1],[$dstItem=>3],$pdf()));
        $sourceAfter=DB::table('hall_inventory_positions')
            ->where('hall_id',$src->id)->where('item_id',$srcItem)->first();
        $destAfter=DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->first();
        $this->assertSame(21,(int)$sourceAfter->owned_usable);
        $this->assertSame(8,(int)$sourceAfter->owned_on_loan);
        $this->assertSame(1,(int)$sourceAfter->owned_damaged);
        $this->assertSame(4,(int)$destAfter->borrowed_usable);
        $this->assertSame(4,(int)$destAfter->borrowed_transit);
        $this->assertSame(0,(int)$destAfter->borrowed_damaged);
        $this->assertSame(3,(int)$sourceAfter->revision);
        $this->assertSame(4,(int)$destAfter->revision);
        $this->assertSame(1,DB::table('hall_inventory_internal_loan_return_acceptances')->count());
        $acceptedLine=DB::table('hall_inventory_internal_loan_return_acceptance_lines')->first();
        $this->assertSame(3,(int)$acceptedLine->good_quantity);
        $this->assertSame(1,(int)$acceptedLine->damaged_quantity);
        $this->assertSame(1,(int)$acceptedLine->unresolved_quantity);
        $this->assertSame(1,DB::table('hall_inventory_movement_events')
            ->where('event_kind','return_owned_good')->count());
        $this->assertSame(1,DB::table('hall_inventory_movement_events')
            ->where('event_kind','return_owned_damaged')->count());
        $this->assertSame(1,DB::table('hall_inventory_movement_events')
            ->where('event_kind','confirm_borrowed_return')->count());
        try{
            $acceptor->accept($owner,$company->id,$first,$acceptance,
                [$srcItem=>['good'=>4,'damaged'=>0]],[$srcItem=>1],[$dstItem=>3],$pdf());
            $this->fail('Changed accepted return condition replayed.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('finance_journals')->count());
        $finalHistory=$read->sourceBatches($owner,$company->id,$src->id);
        $this->assertCount(2,$finalHistory);
        $recorded=array_values(array_filter($finalHistory,fn($row)=>$row['accepted']));
        $pending=array_values(array_filter($finalHistory,fn($row)=>!$row['accepted']));
        $this->assertCount(1,$recorded);
        $this->assertCount(1,$pending);
        $this->assertSame('SOURCE_REVIEW_RECORDED_OUTSTANDING_CHECK_REQUIRED',$recorded[0]['state']);
        $this->assertSame(3,$recorded[0]['lines'][0]['goodQuantity']);
        $this->assertSame(1,$recorded[0]['lines'][0]['damagedQuantity']);
        $this->assertSame(1,$recorded[0]['lines'][0]['unresolvedQuantity']);
        $this->assertNull($pending[0]['lines'][0]['goodQuantity']);
        $this->assertNull($pending[0]['lines'][0]['damagedQuantity']);
        $this->assertNull($pending[0]['lines'][0]['unresolvedQuantity']);
        // Outstanding transit and damages require further reconciliation.
        // Source can independently acknowledge the second batch as well,
        // but even after every batch has a review, unresolved original
        // quantities and damage prohibit a completed-loan label.
        $secondAcceptance=(string)Str::uuid();
        $acceptor->accept($owner,$company->id,$second,$secondAcceptance,
            [$srcItem=>['good'=>3,'damaged'=>0]],[$srcItem=>3],[$dstItem=>4],$pdf());
        $loanRows=app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($owner,$company->id,$src->id);
        $loanHistory=collect($loanRows)->firstWhere('id',$loan);
        $this->assertSame('return_acknowledged_outstanding_reconciliation_required',
            $loanHistory['status']);
        $this->assertTrue($loanHistory['stockMoved']);
        $summary=$loanHistory['returnReconciliation'];
        $this->assertSame(12,$summary['receivedQuantity']);
        $this->assertSame(8,$summary['shippedQuantity']);
        $this->assertSame(4,$summary['notYetShipped']);
        $this->assertSame(6,$summary['sourceAcknowledgedGood']);
        $this->assertSame(1,$summary['sourceAcknowledgedDamaged']);
        $this->assertSame(1,$summary['sourceUnresolved']);
        $this->assertSame(0,$summary['sourceAcknowledgmentPending']);
        $this->assertSame(5,$summary['stillUnsettled']);
        $this->assertFalse($summary['integrityWarning']);
        $this->assertFalse($summary['loanCompleted']);
        $this->assertFalse($summary['financiallySettled']);
        $this->assertSame(1,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$dstItem)->value('borrowed_transit'));
        $this->assertSame(0,DB::table('finance_journals')->count());
        $custody=app(HallInventoryInternalLoanReturnReview::class)
            ->abnormalSourceLines($owner,$company->id,$src->id,$loan);
        $this->assertCount(1,$custody);
        $this->assertSame(12,$custody[0]['dispatched']);
        $this->assertSame(6,$custody[0]['good']);
        $this->assertSame(1,$custody[0]['damaged']);
        $this->assertSame(1,$custody[0]['unresolved']);
        $this->assertSame(0,$custody[0]['inTransit']);
        $this->assertSame(4,$custody[0]['notReturned']);
        $this->assertSame(4,$custody[0]['borrowerBookUsable']);
        $this->assertSame(1,$custody[0]['borrowerBookTransit']);
        $this->assertSame(0,$custody[0]['borrowerBookDamaged']);
        $this->assertFalse($custody[0]['integrityWarning']);
        $this->assertTrue($custody[0]['closureBlocked']);
        $this->assertFalse($custody[0]['moneyPosted']);
        try {
            $this->be($receiver);
            app(HallInventoryInternalLoanReturnReview::class)
                ->abnormalSourceLines($receiver,$company->id,$src->id,$loan);
            $this->fail('Borrower accessed original lender abnormal custody.');
        } catch (AuthorizationException) {}
        $this->be($owner);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertFalse($loanHistory['overdueWithOutstandingCustody']);
        $incidentSvc=app(\App\Services\HallInventoryLoanIncidentService::class);
        $incidentId=(string)Str::uuid();
        try {
            $incidentSvc->report($owner,$company->id,$loan,$incidentId,
                $srcItem,'damage',1,'Physically damaged tray at return.');
            $this->fail('Unreleased incident recording was allowed.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_incidents_enabled',true);
        $this->assertSame($incidentId,$incidentSvc->report(
            $owner,$company->id,$loan,$incidentId,$srcItem,'damage',1,
            'Physically damaged tray at return.'));
        $this->assertSame($incidentId,$incidentSvc->report(
            $owner,$company->id,$loan,$incidentId,$srcItem,'damage',1,
            'Physically damaged tray at return.'));
        try {
            $incidentSvc->report($owner,$company->id,$loan,(string)Str::uuid(),
                $srcItem,'damage',2,'Inflated damage count should fail.');
            $this->fail('Incident inflated damaged quantity.');
        }catch(ConflictHttpException){}
        $this->be($receiver);
        try {
            $incidentSvc->report($receiver,$company->id,$loan,(string)Str::uuid(),
                $srcItem,'missing',1,'Unauthorized borrowing manager report.');
            $this->fail('Borrower issued a source incident.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $history=$incidentSvc->history($owner,$company->id,$src->id,$loan);
        $this->assertCount(1,$history);
        // Separate missing-custody evidence may be submitted by the source
        // Owner, but it is neither an accepted return nor stock retirement.
        $missingId=(string)Str::uuid();
        $this->assertSame($missingId,$incidentSvc->report($owner,$company->id,
            $loan,$missingId,$srcItem,'missing',1,
            'One source quantity remains physically unreturned and disputed.'));
        $this->assertSame($missingId,$incidentSvc->report($owner,$company->id,
            $loan,$missingId,$srcItem,'missing',1,
            'One source quantity remains physically unreturned and disputed.'));
        $pending=app(HallInventoryInternalLoanReturnReview::class)
            ->abnormalSourceLines($owner,$company->id,$src->id,$loan);
        $this->assertSame(1,$pending[0]['pendingDiscrepancyReview']);
        $this->assertSame(0,$pending[0]['substantiatedMissingEvidence']);
        $this->assertSame(4,$pending[0]['notReturned']);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame('damage',$history[0]['kind']);
        // Verify the actual authenticated Owner screen and its rendered
        // original-hall custody, rather than only invoking a read service.
        config()->set('inventory_controls.catalog_enabled',true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        Livewire::test(HallInventory::class)
            ->set('companyId',$company->id)
            ->set('hallId',$src->id)
            ->call('startLoanIncident',$loan)
            ->assertHasNoErrors()
            ->assertSet('incidentLoanId',$loan)
            ->assertSee('Original lending hall physical custody (read-only)')
            ->assertSee('dispatched 12,')
            ->assertSee('good 6, damaged 1,')
            ->assertSee('destination item aggregate borrowed usable 4')
            ->assertSee('destination item buckets may combine multiple loans; not signed borrower acceptance')
            ->assertSee('Closure BLOCKED; no money posted by this reconciliation.');
        // A hall change must discard already rendered source custody.
        $page=Livewire::test(HallInventory::class)
            ->set('companyId',$company->id)->set('hallId',$src->id)
            ->call('startLoanIncident',$loan)
            ->assertSee('dispatched 12,');
        $page->set('hallId',$dest->id)
            ->assertSet('internalAbnormalCustody',[])
            ->assertDontSee('Original lending hall physical custody (read-only)');
        // This rendered custody is server-owned, never client-submitted.
        $property=new \ReflectionProperty(HallInventory::class,'internalAbnormalCustody');
        $this->assertNotEmpty($property->getAttributes(\Livewire\Attributes\Locked::class));

        $this->be($receiver);
        Livewire::test(HallInventory::class)
            ->set('companyId',$company->id)
            ->set('hallId',$dest->id)
            ->call('startLoanIncident',$loan)
            ->assertForbidden();
        $this->be($owner);
        $this->assertSame(0,DB::table('finance_journals')->count());

        $reviewSvc=app(\App\Services\HallInventoryLoanIncidentDecisionService::class);
        $reviewId=(string)Str::uuid();
        try {
            $reviewSvc->decide($owner,$company->id,$incidentId,$reviewId,
                'substantiated','Independent photographed physical damage.');
            $this->fail('Incident review ignored release gate.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_incident_reviews_enabled',true);
        try {
            $reviewSvc->decide($owner,$company->id,$incidentId,$reviewId,
                'substantiated','Independent photographed physical damage.');
            $this->fail('Reporter self-approved incident.');
        }catch(AuthorizationException){}
        $this->be($dispatcher);
        $this->assertSame($reviewId,$reviewSvc->decide($dispatcher,$company->id,
            $incidentId,$reviewId,'substantiated','Independent photographed physical damage.'));
        $this->assertSame($reviewId,$reviewSvc->decide($dispatcher,$company->id,
            $incidentId,$reviewId,'substantiated','Independent photographed physical damage.'));
        try {
            $reviewSvc->decide($dispatcher,$company->id,$incidentId,(string)Str::uuid(),
                'unsubstantiated','Conflicting second review should be rejected.');
            $this->fail('Second review replaced immutable decision.');
        }catch(ConflictHttpException){}
        $reviewRows=$reviewSvc->reviews($dispatcher,$company->id,$src->id,$loan);
        $this->assertCount(2,$reviewRows);
        $missingDecision=(string)Str::uuid();
        $this->assertSame($missingDecision,$reviewSvc->decide($dispatcher,
            $company->id,$missingId,$missingDecision,'substantiated',
            'Independent source-hall discrepancy evidence examined.'));
        $confirmed=app(HallInventoryInternalLoanReturnReview::class)
            ->abnormalSourceLines($dispatcher,$company->id,$src->id,$loan);
        $this->assertSame(1,$confirmed[0]['substantiatedMissingEvidence']);
        $this->assertSame(0,$confirmed[0]['pendingDiscrepancyReview']);
        $this->be($owner);
        $disputeId=(string)Str::uuid();
        $incidentSvc->report($owner,$company->id,$loan,$disputeId,
            $srcItem,'dispute',1,'Borrower disputed original return quantity pending independent documents.');
        $this->be($dispatcher);
        $reviewSvc->decide($dispatcher,$company->id,$disputeId,(string)Str::uuid(),
            'requires_more_evidence','Independent review requests further witnessed return evidence.');
        $open=app(HallInventoryInternalLoanReturnReview::class)
            ->abnormalSourceLines($dispatcher,$company->id,$src->id,$loan);
        $this->assertSame(1,$open[0]['pendingDiscrepancyReview']);
        $this->assertSame(1,$open[0]['substantiatedMissingEvidence']);
        $this->assertSame(0,$open[0]['substantiatedDisputeEvidence']);
        $this->assertSame(4,$open[0]['notReturned']);
        $this->assertSame(4,$open[0]['borrowerBookUsable']);
        $this->assertSame(1,$open[0]['borrowerBookTransit']);
        $this->assertSame(0,DB::table('finance_journals')->count());

        $this->assertSame(4,$confirmed[0]['notReturned']);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame('substantiated',collect($reviewRows)->firstWhere('reportId',$incidentId)['decision']);
        config()->set('inventory_controls.loan_nonclean_reconciliation_enabled',true);
        config()->set('inventory_controls.loan_nonclean_writeoffs_enabled',true);
        $third=User::create(['name'=>'Independent damaged stock reviewer',
            'email'=>'internal-writeoff-reviewer@example.test','password'=>'test-only']);
        $third->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$third->id,'role'=>'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        // Stale or forged form IDs cannot select evidence from any different loan.
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$src->id)->call('startLoanIncident',$loan)
            ->set('internalDamageIncidentId',(string)Str::uuid())
            ->set('internalDamageQuantity','1')
            ->set('internalDamageReason','Cross loan evidence cannot be silently selected')
            ->call('proposeInternalDamageDisposition')->assertForbidden();
        $this->assertSame(0,DB::table('hall_inventory_nonclean_writeoff_proposals')->count());
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$src->id)->call('startLoanIncident',$loan)
            ->assertSee('Damaged owned stock: independent physical disposition')
            ->set('internalDamageIncidentId',$incidentId)
            ->set('internalDamageQuantity','1')
            ->set('internalDamageReason','Documented damaged owned serving tray disposal')
            ->call('proposeInternalDamageDisposition')->assertHasNoErrors();
        $proposal=(string)DB::table('hall_inventory_nonclean_writeoff_proposals')
            ->where('incident_id',$incidentId)->value('id');
        $this->assertTrue(Str::isUuid($proposal));
        $this->be($owner);
        try {
            app(\App\Services\HallInventoryNoncleanWriteoffService::class)
                ->decide($owner,$company->id,$src->id,$proposal,(string)Str::uuid(),
                    'approved','Cannot self authorize damage');
            $this->fail('Reporter authorized own physical damage.');
        }catch(AuthorizationException){}
        $this->be($third);
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$src->id)->call('startLoanIncident',$loan)
            ->set('internalDamageReviewProposalId',(string)Str::uuid())
            ->set('internalDamageReviewDecision','approved')
            ->set('internalDamageReviewReason','Forged foreign proposal rejected by source panel')
            ->call('decideInternalDamageDisposition')->assertForbidden();
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$src->id)->call('startLoanIncident',$loan)
            ->set('internalDamageReviewProposalId',$proposal)
            ->set('internalDamageReviewDecision','approved')
            ->set('internalDamageReviewReason','Independently verified damaged return evidence')
            ->call('decideInternalDamageDisposition')->assertHasNoErrors()
            ->assertSee('approved');
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$src->id)->where('item_id',$srcItem)->value('owned_damaged'));
        $this->be($third);
        $retired=app(HallInventoryInternalLoanReturnReview::class)
            ->abnormalSourceLines($third,$company->id,$src->id,$loan);
        $this->assertSame(1,$retired[0]['damaged']);
        $this->assertSame(1,$retired[0]['approvedDamagedRetirement']);
        $this->assertSame(0,$retired[0]['remainingDamagedForDisposition']);
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$src->id)->call('startLoanIncident',$loan)
            ->assertSee('independently retired damaged 1')
            ->assertSee('damaged still awaiting disposition 0')
            ->assertSee('Closure BLOCKED; no money posted by this reconciliation.');
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->be($dispatcher);

        $this->assertFalse($reviewRows[0]['monetaryObligationSettled']);
        $this->assertFalse($reviewRows[0]['loanClosed']);
        $this->be($receiver);
        try {
            $reviewSvc->reviews($receiver,$company->id,$src->id,$loan);
            $this->fail('Borrowing hall manager read source private review.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $this->assertFalse($history[0]['financiallyResolved']);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->travelTo(\Illuminate\Support\Carbon::parse('2028-01-02 12:00:00'));
        try {
            $overdueRows=app(\App\Services\HallInventoryLoanDraftReview::class)
                ->recent($owner,$company->id,$src->id);
            $overdue=collect($overdueRows)->firstWhere('id',$loan);
            $this->assertTrue($overdue['overdueWithOutstandingCustody']);
            $this->assertSame(5,$overdue['returnReconciliation']['stillUnsettled']);
            $this->assertFalse($overdue['returnReconciliation']['loanCompleted']);
            $this->assertSame(0,DB::table('finance_journals')->count());
        } finally {
            $this->travelBack();
        }

        // A loaded owner panel must recheck membership after revocation,
        // regardless of previously cached company/hall and custody state.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $loaded=Livewire::test(HallInventory::class)
            ->set('companyId',$company->id)->set('hallId',$src->id)
            ->call('startLoanIncident',$loan)
            ->assertHasNoErrors()->assertSee('dispatched 12,');
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$owner->id)->update(['is_active'=>false]);
        $loaded->call('startLoanIncident',$loan)->assertForbidden();
        $this->assertSame(0,DB::table('finance_journals')->count());

    }
}
