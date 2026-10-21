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
use App\Services\HallInventoryLoanIncidentService;
use App\Services\HallInventoryLoanIncidentDecisionService;
use App\Services\HallInventoryFinancialClaimService;
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

final class HallInventoryExternalOutIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_owned_damage_missing_and_dispute_are_evidenced_and_independently_reviewed_without_financial_assumption():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'External Owned Incident Review','slug'=>'external-owned-incident-review']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Incident Source Hall',
            'slug'=>'incident-source-hall','capacity'=>120]);
        $wrong=Hall::create(['company_id'=>$company->id,'name'=>'Other Incident Hall',
            'slug'=>'other-incident-hall','capacity'=>80]);
        $drafter=User::create(['name'=>'Incident Draft Manager',
            'email'=>'ext-inc-drafter@example.test','password'=>'test-only']);
        $witness=User::create(['name'=>'Physical Incident Witness',
            'email'=>'ext-inc-witness@example.test','password'=>'test-only']);
        $owner=User::create(['name'=>'Incident Reporting Owner',
            'email'=>'ext-inc-owner@example.test','password'=>'test-only']);
        $reviewer=User::create(['name'=>'Independent Incident Reviewer',
            'email'=>'ext-inc-reviewer@example.test','password'=>'test-only']);
        foreach([[$drafter,'manager'],[$witness,'manager'],[$owner,'owner'],
                 [$reviewer,'owner']] as [$u,$role]){
            $u->markEmailAsVerified();
            $m=CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'hall_id'=>$hall->id,'membership_id'=>$m->id]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach(['catalog_enabled','loan_drafts_enabled','external_out_dispatch_enabled',
                 'external_out_return_enabled','loan_incidents_enabled',
                 'loan_incident_reviews_enabled'] as $flag)
            config()->set('inventory_controls.'.$flag,true);
        // Existing INTERNAL return/incident gate intentionally stays false.
        $this->assertFalse(config('inventory_controls.loan_return_acceptance_enabled'));
        $this->be($drafter);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($drafter,$company->id,
            $hall->id,$item,'Lent outside damaged folding table',
            'Rental furniture','piece',1,'Signed outside incident item');
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,
            'hall_id'=>$hall->id,'item_id'=>$item,
            'owned_usable'=>10,'created_at'=>now(),'updated_at'=>now()
        ]);
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,$loan,
            'external_out',$hall->id,null,[['itemId'=>$item,'quantity'=>5]],
            '2027-12-15','External partner damage and missing investigation',
            'Outside Inventory Customer','Signed receiving partner');
        $pdf=static fn()=>UploadedFile::fake()->createWithContent(
            'outside-evidence.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->be($witness);
        app(HallInventoryExternalOutDispatchService::class)->record(
            $witness,$company->id,$hall->id,$loan,(string)Str::uuid(),
            $pdf(),[$item=>5]);
        $this->be($owner);
        app(HallInventoryExternalOutDispatchService::class)->approve(
            $owner,$company->id,$hall->id,$loan,(string)Str::uuid());
        $this->be($witness);
        $receipt=(string)Str::uuid();
        app(HallInventoryExternalOutReturnService::class)->receive($witness,
            $company->id,$hall->id,$loan,$receipt,[$item=>2],$pdf());
        $this->be($owner);
        app(HallInventoryExternalOutReturnService::class)->review($owner,
            $company->id,$hall->id,$receipt,(string)Str::uuid(),
            [$item=>['good'=>1,'damaged'=>1,'unresolved'=>0]]);
        $service=app(HallInventoryLoanIncidentService::class);
        try{
            $service->report($owner,$company->id,$loan,(string)Str::uuid(),
                $item,'damage',2,'Invalid larger than witnessed damaged physical quantity');
            $this->fail('Unverified damage quantities permitted.');
        }catch(ConflictHttpException){}
        try{
            $service->report($owner,$company->id,$loan,(string)Str::uuid(),
                $item,'missing',4,'Invalid larger than remaining unreturned owned goods');
            $this->fail('Missing quantities greater than outstanding loan permitted.');
        }catch(ConflictHttpException){}
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('Review damage, missing or disputed items')
            ->call('startLoanIncident',$loan)->assertHasNoErrors()
            ->set('incidentItemId',$item)->set('incidentKind','damage')
            ->set('incidentQuantity','1')
            ->set('incidentReason','Physically returned table has independently confirmed broken legs')
            ->call('submitLoanIncident')->assertHasNoErrors()
            ->assertSee('damage: 1 units');
        $report=(string)DB::table('hall_inventory_loan_incident_reports')
            ->where('company_id',$company->id)->where('loan_id',$loan)->value('id');
        $this->assertTrue(Str::isUuid($report));
        $missing=(string)Str::uuid();
        $this->assertSame($missing,$service->report($owner,$company->id,$loan,
            $missing,$item,'missing',3,'Three unreturned partner loan units remain unaccounted for'));
        $this->assertSame(2,DB::table('hall_inventory_loan_incident_reports')->count());
        $decision=app(HallInventoryLoanIncidentDecisionService::class);
        try{
            $decision->decide($owner,$company->id,$report,(string)Str::uuid(),
                'substantiated','Cannot independently review my own incident');
            $this->fail('Incident reporter self-approved their own finding.');
        }catch(AuthorizationException){}
        $this->be($reviewer);
        Livewire::test(HallInventory::class)->set('hallId',$wrong->id)
            ->call('startLoanIncident',$loan)->assertForbidden();
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startLoanIncident',$loan)->assertHasNoErrors()
            ->set('incidentDecisionReportId',$report)
            ->set('incidentDecision','substantiated')
            ->set('incidentDecisionReason','Independent physical return count substantiates documented damage')
            ->call('submitLoanIncidentReview')->assertHasNoErrors();
        $decisionRow=DB::table('hall_inventory_loan_incident_decisions')
            ->where('company_id',$company->id)->where('report_id',$report)->first();
        $this->assertNotNull($decisionRow);
        $this->assertSame($decisionRow->id,$decision->decide(
            $reviewer,$company->id,$report,$decisionRow->id,
            'substantiated','Independent physical return count substantiates documented damage'));
        try{
            $decision->decide($reviewer,$company->id,$report,(string)Str::uuid(),
                'unsubstantiated','Different conflicting unsubstantiated conclusion');
            $this->fail('Second conflicting independent review accepted.');
        }catch(ConflictHttpException){}
        // Proposed loss amounts require independently substantiated physical evidence.
        $claims=app(\App\Services\HallInventoryFinancialClaimService::class);
        try{
            $claims->propose($reviewer,$company->id,$report,(string)Str::uuid(),
                'USD',25000,'Collection request without feature release permission');
            $this->fail('Default-disabled monetary claim was accepted.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_financial_claims_enabled',true);
        $this->be($owner);
        try{
            $claims->propose($owner,$company->id,$missing,(string)Str::uuid(),
                'USD',15000,'Unsubstantiated missing item must not be chargeable');
            $this->fail('Unreviewed incident created a monetary claim.');
        }catch(ConflictHttpException){}
        // End-to-end owner-facing claim submission, not only the underlying service.
        Livewire::test(HallInventory::class)->set('hallId',$wrong->id)
            ->call('startFinancialClaims',$loan)->assertForbidden();
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->assertSee('Review provisional damage/loss claims')
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->assertSee('Outside lending provisional loss claims')
            ->set('financialClaimReportId',$report)
            ->set('financialClaimCurrency','USD')
            ->set('financialClaimAmountMinor','25000')
            ->set('financialClaimReason','Physical damaged table independently substantiated and quoted')
            ->call('submitFinancialClaim')->assertHasNoErrors()
            ->assertSee('AWAITING INDEPENDENT REVIEW')
            ->assertSee('Provisional claim alone does not post money');
        $claim=(string)DB::table('hall_inventory_financial_claims')
            ->where('company_id',$company->id)->where('report_id',$report)->value('id');
        $this->assertTrue(Str::isUuid($claim));
        $this->assertSame($claim,$claims->propose($owner,$company->id,$report,$claim,
            'USD',25000,'Physical damaged table independently substantiated and quoted'));
        $this->assertSame($claim,$claims->propose($owner,$company->id,$report,$claim,
            'USD',25000,'Physical damaged table independently substantiated and quoted'));
        try{
            $claims->propose($owner,$company->id,$report,(string)Str::uuid(),
                'USD',75000,'Duplicate financial assessment must not double charge');
            $this->fail('Conflicting financial proposal accepted.');
        }catch(ConflictHttpException){}
        try{
            $claims->decide($owner,$company->id,$claim,(string)Str::uuid(),
                'approved_for_collection','Self-approval must never be allowed');
            $this->fail('Incident reporter approved their own monetary claim.');
        }catch(AuthorizationException){}
        $this->be($reviewer);
        $reason='Independent price basis review approved for COLLECTION ONLY';
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->assertSee('Independent owner/admin claim assessment')
            ->set('financialClaimDecisionClaimId',$claim)
            ->set('financialClaimDecision','approved_for_collection')
            ->set('financialClaimDecisionReason',$reason)
            ->call('submitFinancialClaimDecision')->assertHasNoErrors()
            ->assertSee('approved_for_collection')
            ->assertDontSee('AWAITING INDEPENDENT REVIEW');
        $decisionId=(string)DB::table('hall_inventory_financial_claim_decisions')
            ->where('company_id',$company->id)->where('claim_id',$claim)->value('id');
        $this->assertTrue(Str::isUuid($decisionId));
        $this->assertSame($decisionId,$claims->decide($reviewer,$company->id,
            $claim,$decisionId,'approved_for_collection',$reason));
        $this->assertSame($decisionId,$claims->decide($reviewer,$company->id,
            $claim,$decisionId,'approved_for_collection',$reason));
        try{
            $claims->decide($reviewer,$company->id,$claim,(string)Str::uuid(),
                'disputed','Second contradictory claim decision cannot override first');
            $this->fail('Prior immutable financial decision was overwritten.');
        }catch(ConflictHttpException){}
        $history=$claims->history($reviewer,$company->id,$hall->id,$loan);
        $this->assertCount(1,$history);
        $this->assertSame('approved_for_collection',$history[0]['decision']);
        $this->assertSame(25000,$history[0]['amountMinor']);
        $this->assertSame(0,$history[0]['paidMinor']);
        $this->assertFalse($history[0]['journalPosted']);
        try{
            $claims->history($reviewer,$company->id,$wrong->id,$loan);
            $this->fail('Cross-hall incident claim history was disclosed.');
        }catch(AuthorizationException){}
        try{
            DB::transaction(fn()=>DB::table('hall_inventory_financial_claims')
                ->where('id',$claim)->update(['amount_minor'=>1]));
            $this->fail('Claim amount was silently edited.');
        }catch(\Illuminate\Database\QueryException){}
        $this->assertSame(25000,(int)DB::table('hall_inventory_financial_claims')
            ->where('id',$claim)->value('amount_minor'));
        $this->assertSame(1,DB::table('hall_inventory_financial_claim_decisions')->count());
        // Second independent documentary workflow: private custody, disputed
        // partner response, separate review, and no automatic financial charge.
        config()->set('inventory_controls.loan_partner_responses_enabled',true);
        $this->be($owner);
        $partnerPdf=static fn()=>UploadedFile::fake()->createWithContent(
            'partner-response.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->set('financialPartnerClaimId',$claim)
            ->set('financialPartnerPosition','document_says_disputes')
            ->set('financialPartnerFile',$partnerPdf())
            ->call('submitFinancialPartnerEvidence')->assertHasNoErrors()
            ->assertSee('PENDING DOCUMENT REVIEW')
            ->assertSee('Journal posted: NO');
        $document=(string)DB::table('hall_inventory_financial_counterparty_evidence')
            ->where('company_id',$company->id)->where('claim_id',$claim)->value('id');
        $this->assertTrue(Str::isUuid($document));
        $partner=app(\App\Services\HallInventoryFinancialCounterpartyEvidenceService::class);
        $this->assertSame($document,$partner->record($owner,$company->id,$claim,
            $document,'document_says_disputes',null,$partnerPdf()));
        // A conflicting UUID must preserve private bytes, even when an
        // interrupted prior issuance left no ledger row behind.
        $partnerOccupiedId=(string)Str::uuid();
        $partnerOccupiedPath='private/hall-inventory-financial-partner/'.$company->id
            .'/'.$claim.'/'.$partnerOccupiedId;
        $partnerOriginalBytes="%PDF-1.4\nPreviously signed evidence\n%%EOF\n";
        Storage::disk('local')->put($partnerOccupiedPath,$partnerOriginalBytes);
        try {
            $partner->record($owner,$company->id,$claim,$partnerOccupiedId,
                'other_document',null,$partnerPdf());
            $this->fail('An existing immutable evidence file was overwritten.');
        } catch (ConflictHttpException) {}
        $this->assertSame($partnerOriginalBytes,Storage::disk('local')->get($partnerOccupiedPath));
        $this->assertSame(1,DB::table('hall_inventory_financial_counterparty_evidence')->count());
        Storage::disk('local')->delete($partnerOccupiedPath);
        try {
            $partner->record($owner,$company->id,$claim,(string)Str::uuid(),
                'document_says_disputes',null,$partnerPdf());
            $this->fail('Duplicated identical partner correspondence with a new id.');
        }catch(ConflictHttpException){}
        try {
            $partner->review($owner,$company->id,$document,(string)Str::uuid(),
                'document_integrity_confirmed','Cannot independently review my submitted response');
            $this->fail('Partner evidence reporter reviewed their own submission.');
        }catch(AuthorizationException){}
        $this->be($reviewer);
        Livewire::test(HallInventory::class)->set('hallId',$wrong->id)
            ->call('startFinancialClaims',$loan)->assertForbidden();
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->assertSee('Partner document custody and independent review')
            ->set('financialPartnerReviewEvidenceId',$document)
            ->set('financialPartnerAssessment','document_integrity_confirmed')
            ->set('financialPartnerReviewReason','Stored signed partner document matches submitted bytes but signer needs verification')
            ->call('submitFinancialPartnerReview')->assertHasNoErrors()
            ->assertSee('document_integrity_confirmed');
        $reviewId=(string)DB::table('hall_inventory_financial_counterparty_reviews')
            ->where('company_id',$company->id)->where('evidence_id',$document)->value('id');
        $this->assertTrue(Str::isUuid($reviewId));
        $this->assertSame($reviewId,$partner->review($reviewer,$company->id,$document,
            $reviewId,'document_integrity_confirmed',
            'Stored signed partner document matches submitted bytes but signer needs verification'));
        $private=$partner->download($reviewer,$company->id,$hall->id,$loan,$document);
        $this->assertContains('private',array_map('trim',explode(',',(string)$private->headers->get('Cache-Control'))));
        $this->assertContains('no-store',array_map('trim',explode(',',(string)$private->headers->get('Cache-Control'))));
        $this->assertSame('nosniff',$private->headers->get('X-Content-Type-Options'));
        $docHistory=$partner->history($reviewer,$company->id,$hall->id,$loan);
        $this->assertCount(1,$docHistory);
        $this->assertFalse($docHistory[0]['partnerIdentityVerified']);
        $this->assertSame(0,$docHistory[0]['paidMinor']);
        try {
            $partner->history($reviewer,$company->id,$wrong->id,$loan);
            $this->fail('Other hall partner document history disclosed.');
        }catch(AuthorizationException){}
        try {
            $partner->download($reviewer,$company->id,$wrong->id,$loan,$document);
            $this->fail('Other hall partner evidence downloaded.');
        }catch(AuthorizationException){}
        $record=DB::table('hall_inventory_financial_counterparty_evidence')
            ->where('id',$document)->first();
        $original=Storage::disk('local')->get($record->evidence_path);
        Storage::disk('local')->put($record->evidence_path,'tampered bytes');
        try {
            $partner->download($reviewer,$company->id,$hall->id,$loan,$document);
            $this->fail('Tampered private partner correspondence downloaded.');
        }catch(ConflictHttpException){}
        Storage::disk('local')->put($record->evidence_path,$original);
        $forged=(array)$record;
        $forged['id']=(string)Str::uuid();
        $forged['source_hall_id']=$wrong->id;
        $forged['fingerprint']=hash('sha256','foreign-hall partner record');
        try {
            DB::transaction(fn()=>DB::table('hall_inventory_financial_counterparty_evidence')
                ->insert($forged));
            $this->fail('Database accepted forged partner evidence from another hall.');
        }catch(\Illuminate\Database\QueryException){}
        try {
            DB::transaction(fn()=>DB::table('hall_inventory_financial_counterparty_evidence')
                ->where('id',$document)->update(['position'=>'other_document']));
            $this->fail('Immutable partner evidence was updated.');
        }catch(\Illuminate\Database\QueryException){}
        $this->assertSame('document_says_disputes',
            DB::table('hall_inventory_financial_counterparty_evidence')
                ->where('id',$document)->value('position'));
        // A disputed document must never be accepted as a collectible agreement.
        config()->set('inventory_controls.loan_authorized_obligations_enabled',true);
        $obligations=app(\App\Services\HallInventoryFinancialObligationService::class);
        $this->be($owner);
        try {
            $obligations->propose($owner,$company->id,$claim,$document,(string)Str::uuid(),
                25000,'verified_callback','Recorded signed partner reference ABC123',
                'Attempted liability from expressly DISPUTED response');
            $this->fail('Disputed response was accepted as verified agreement.');
        }catch(ConflictHttpException){}
        // Second, distinct received document claims acceptance. The claim remains
        // wholly unposted until the real owner independently approves liability.
        $acceptDoc=(string)Str::uuid();
        $this->assertSame($acceptDoc,$partner->record($owner,$company->id,$claim,
            $acceptDoc,'document_says_accepts',null,$partnerPdf()));
        $this->be($reviewer);
        $this->assertSame(1,DB::table('hall_inventory_financial_counterparty_reviews')->count());
        $partner->review($reviewer,$company->id,$acceptDoc,(string)Str::uuid(),
            'document_integrity_confirmed',
            'Independently checked accepted partner signed document bytes only');
        $this->be($owner);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->assertSee('Manually attested collectible obligation approval')
            ->set('financialObligationEvidenceId',$acceptDoc)
            ->set('financialObligationAmountMinor','25000')
            ->set('financialObligationIdentityMethod','verified_callback')
            ->set('financialObligationIdentityReference','Approved partner contact callback ref INVTEST123')
            ->set('financialObligationReason','Physical inventory damage payable only after independent owner attestation')
            ->call('submitFinancialObligation')->assertHasNoErrors()
            ->assertSee('PENDING INDEPENDENT OBLIGATION REVIEW');
        $obligationId=(string)DB::table('hall_inventory_financial_obligations')
            ->where('company_id',$company->id)->where('claim_id',$claim)->value('id');
        $this->assertTrue(Str::isUuid($obligationId));
        try {
            $obligations->decide($owner,$company->id,$obligationId,(string)Str::uuid(),
                'authorized','Unauthorized self approval must fail');
            $this->fail('Financial obligation was self-approved.');
        }catch(AuthorizationException){}
        $this->be($reviewer);
        Livewire::test(HallInventory::class)->set('hallId',$wrong->id)
            ->call('startFinancialClaims',$loan)->assertForbidden();
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->set('financialObligationDecisionProposalId',$obligationId)
            ->set('financialObligationDecision','authorized')
            ->set('financialObligationDecisionReason','Independent owner authorized documented amount for collection not journal')
            ->call('submitFinancialObligationDecision')->assertHasNoErrors()
            ->assertSee('Decision: authorized');
        $this->assertSame('authorized',
            DB::table('hall_inventory_financial_obligation_decisions')
                ->where('obligation_id',$obligationId)->value('decision'));
        $agreementHistory=$obligations->history($reviewer,$company->id,$hall->id,$loan);
        $this->assertCount(1,$agreementHistory);
        $this->assertFalse($agreementHistory[0]['receivableJournalPosted']);
        $this->assertSame(0,$agreementHistory[0]['paidMinor']);
        $this->assertSame(0,DB::table('finance_journals')->count());
        // Verified money remains separate from the previously approved
        // obligation. Pending documentation does not affect the finance ledger.
        config()->set('inventory_controls.loan_recoveries_enabled',true);
        $recoveries=app(\App\Services\HallInventoryFinancialRecoveryService::class);
        $this->be($owner);
        $payfile=static fn()=>UploadedFile::fake()->createWithContent(
            'verified-bank-evidence.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->assertSee('Separately verified partial and full loss recoveries')
            ->set('financialRecoveryObligationId',$obligationId)
            ->set('financialRecoveryMethod','bank')
            ->set('financialRecoveryAmountMinor','12000')
            ->set('financialRecoveryBankReference','WIRE-RECOVERY-ONE-INV123')
            ->set('financialRecoveryFile',$payfile())
            ->call('submitFinancialRecovery')->assertHasNoErrors()
            ->assertSee('AWAITING INDEPENDENT PAYMENT VERIFICATION');
        $receiptId=(string)DB::table('hall_inventory_financial_recovery_receipts')
            ->where('company_id',$company->id)->where('obligation_id',$obligationId)->value('id');
        $this->assertTrue(Str::isUuid($receiptId));
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(12000,$recoveries->outstanding($owner,$company->id,$hall->id,$loan)
            [$obligationId]['pendingMinor']);
        try{
            $recoveries->record($owner,$company->id,$obligationId,(string)Str::uuid(),
                'bank',15000,'WIRE-RECOVERY-TWO-INV456',$payfile());
            $this->fail('Overpayment reservation exceeded remaining monetary obligation.');
        }catch(ConflictHttpException){}
        // Distinguish the finance release gate from independent-actor
        // authorization. The first call is rejected before any posting.
        try{
            $recoveries->verify($owner,$company->id,$receiptId,(string)Str::uuid(),
                'confirmed','verified_bank_statement','Approved statement INVTEST123',
                'Financial release is disabled, even for valid looking evidence');
            $this->fail('Unreleased finance journal posting was accepted.');
        }catch(ConflictHttpException){}
        config()->set('finance_controls.posting_enabled',true);
        try{
            $recoveries->verify($owner,$company->id,$receiptId,(string)Str::uuid(),
                'confirmed','verified_bank_statement','Approved statement INVTEST123',
                'The same payment reporter is forbidden from financial posting');
            $this->fail('Payment reporter independently confirmed their own payment.');
        }catch(AuthorizationException){}
        $this->be($reviewer);
        $download=$recoveries->download($reviewer,$company->id,$hall->id,$loan,$receiptId);
        $cacheDirectives=array_map('trim',explode(',',(string)$download->headers->get('Cache-Control')));
        $this->assertContains('private',$cacheDirectives);
        $this->assertContains('no-store',$cacheDirectives);
        $this->assertSame('nosniff',$download->headers->get('X-Content-Type-Options'));
        try {
            $recoveries->download($reviewer,$company->id,$wrong->id,$loan,$receiptId);
            $this->fail('Other hall private banking proof accessible.');
        }catch(AuthorizationException){}
        $moneyEvidence=DB::table('hall_inventory_financial_recovery_receipts')
            ->where('id',$receiptId)->first();
        $bankOriginal=Storage::disk('local')->get($moneyEvidence->evidence_path);
        Storage::disk('local')->put($moneyEvidence->evidence_path,'tampered bank bytes');
        try {
            $recoveries->download($reviewer,$company->id,$hall->id,$loan,$receiptId);
            $this->fail('Tampered private bank proof downloaded.');
        }catch(ConflictHttpException){}
        Storage::disk('local')->put($moneyEvidence->evidence_path,$bankOriginal);
        Livewire::test(HallInventory::class)->set('hallId',$wrong->id)
            ->call('startFinancialClaims',$loan)->assertForbidden();
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->set('financialRecoveryReceiptId',$receiptId)
            ->set('financialRecoveryDecision','confirmed')
            ->set('financialRecoveryVerificationMethod','verified_bank_statement')
            ->set('financialRecoveryVerificationReference','Real bank statement verified INVTEST123')
            ->set('financialRecoveryReviewReason','Independently reconciled deposited partner funds against private bank records')
            ->call('submitFinancialRecoveryReview')->assertHasNoErrors()
            ->assertSee('Status: confirmed');
        $this->assertSame(1,DB::table('finance_journals')
            ->where('company_id',$company->id)->where('entry_type','inventory_recovery')->count());
        $this->assertSame(2,DB::table('finance_journal_lines')->count());
        $this->assertSame(13000,$recoveries->outstanding($reviewer,$company->id,$hall->id,$loan)
            [$obligationId]['remainingMinor']);
        $this->be($owner);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->set('financialRecoveryReversalReceiptId',$receiptId)
            ->set('financialRecoveryReversalReason','Evidence was posted to an incorrect cash reconciliation batch and must be corrected')
            ->call('reverseFinancialRecovery')->assertHasNoErrors()
            ->assertSee('Reversal:');
        $this->assertSame(2,DB::table('finance_journals')->count());
        $this->assertSame(1,DB::table('finance_journals')
            ->where('entry_type','reversal')->count());
        $this->assertSame(4,DB::table('finance_journal_lines')->count());
        $this->assertSame(0,$recoveries->outstanding($owner,$company->id,$hall->id,$loan)
            [$obligationId]['paidMinor']);
        $correctionId=(string)DB::table('hall_inventory_financial_recovery_reversals')
            ->where('company_id',$company->id)->where('receipt_id',$receiptId)->value('id');
        $this->assertTrue(Str::isUuid($correctionId));
        $this->assertSame($correctionId,$recoveries->reverse($owner,$company->id,
            $receiptId,$correctionId,
            'Evidence was posted to an incorrect cash reconciliation batch and must be corrected'));
        try {
            $recoveries->reverse($owner,$company->id,$receiptId,(string)Str::uuid(),
                'Unauthorized conflicting reversal key cannot alter earlier journal');
            $this->fail('Double reversal of the original posted recovery succeeded.');
        }catch(ConflictHttpException){}
        try {
            $recoveries->record($owner,$company->id,$obligationId,(string)Str::uuid(),
                'bank',11000,'WIRE-RECOVERY-ONE-INV123',$payfile());
            $this->fail('Bank payment reference was reused after journal reversal.');
        }catch(ConflictHttpException){}
        try {
            DB::transaction(fn()=>DB::table('hall_inventory_financial_recovery_receipts')
                ->where('id',$receiptId)->update(['amount_minor'=>1]));
            $this->fail('Original recovery amount was silently modified.');
        }catch(\Illuminate\Database\QueryException){}
        $this->assertSame(12000,(int)DB::table('hall_inventory_financial_recovery_receipts')
            ->where('id',$receiptId)->value('amount_minor'));
        // Cash with separate physical proof remains only pending and is rejected
        // by a different owner; neither act manufactures another finance journal.
        $cashReceipt=(string)Str::uuid();
        $this->assertSame($cashReceipt,$recoveries->record($owner,$company->id,
            $obligationId,$cashReceipt,'cash',8000,null,$payfile()));
        $this->be($reviewer);
        $this->assertSame(8000,$recoveries->outstanding($reviewer,$company->id,$hall->id,$loan)
            [$obligationId]['pendingMinor']);
        $cashReview=(string)Str::uuid();
        $this->assertSame($cashReview,$recoveries->verify($reviewer,$company->id,
            $cashReceipt,$cashReview,'rejected','witnessed_cash_count',
            'Actual independent physical cash count INVTEST123',
            'Independently confirmed supplied cash documentation was not actual cash'));
        $this->assertSame(0,$recoveries->outstanding($reviewer,$company->id,$hall->id,$loan)
            [$obligationId]['pendingMinor']);
        $this->assertSame(2,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_closures')->count());
        $p=DB::table('hall_inventory_positions')->where('company_id',$company->id)
            ->where('hall_id',$hall->id)->where('item_id',$item)->first();
        $this->assertSame(3,(int)$p->owned_on_loan);
        $this->assertSame(6,(int)$p->owned_usable);
        $this->assertSame(1,(int)$p->owned_damaged);
        $this->assertSame(2,DB::table('hall_inventory_loan_incident_reports')->count());
        // Independently audited non-clean readiness is a private READ, never
        // an implicit write-off, collection posting or final PDF certificate.
        $reconciliation=app(\App\Services\HallInventoryNoncleanReconciliationService::class);
        try {
            $reconciliation->report($reviewer,$company->id,$hall->id,$loan);
            $this->fail('Disabled non-clean reconciliation was exposed.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_nonclean_reconciliation_enabled',true);
        $this->be($reviewer);
        try {
            $reconciliation->report($reviewer,$company->id,$wrong->id,$loan);
            $this->fail('Other hall loan reconciliation was disclosed.');
        }catch(AuthorizationException){}
        $snapshot=$reconciliation->report($reviewer,$company->id,$hall->id,$loan);
        $this->assertFalse($snapshot['finalClosureAuthorized']);
        $this->assertContains('unreviewed_incident',$snapshot['closureBlockers']);
        $this->assertContains('outstanding_physical_custody',$snapshot['closureBlockers']);
        $this->assertContains('undisposed_damage',$snapshot['closureBlockers']);
        $this->assertContains('financial_reconciliation_unavailable',$snapshot['closureBlockers']);
        $this->assertSame('2027-12-15',$snapshot['expectedReturnDate']);
        $this->assertCount(1,$snapshot['items']);
        $physical=$snapshot['items'][0];
        $this->assertSame($item,$physical['itemId']);
        $this->assertSame(5,$physical['dispatched']);
        $this->assertSame(1,$physical['good']);
        $this->assertSame(1,$physical['damaged']);
        $this->assertSame(0,$physical['unresolved']);
        $this->assertSame(0,$physical['pendingReview']);
        $this->assertSame(3,$physical['unreturned']);
        $this->assertSame(3,$physical['onLoan']);
        $this->assertSame(2,$snapshot['incidentReports']);
        $this->assertSame(1,$snapshot['unreviewedIncidents']);
        $this->assertSame(25000,$snapshot['obligations'][$obligationId]['amountMinor']);
        $this->assertSame(0,$snapshot['obligations'][$obligationId]['paidMinor']);
        // Duplicate return evidence must fail closed at either the immutable SQL
        // boundary or the independent physical reconciliation boundary.
        $returnLine=(array)DB::table('hall_inventory_external_out_return_lines')
            ->where('receipt_id',$receipt)->where('item_id',$item)->first();
        $this->assertNotEmpty($returnLine);
        try {
            DB::transaction(function () use ($returnLine,$reconciliation,$reviewer,$company,$hall,$loan):void {
                $duplicate=$returnLine;
                $duplicate['id']=(string)Str::uuid();
                DB::table('hall_inventory_external_out_return_lines')->insert($duplicate);
                $reconciliation->report($reviewer,$company->id,$hall->id,$loan);
                $this->fail('Duplicate physical return line accepted.');
            });
        } catch (\Illuminate\Database\QueryException|ConflictHttpException) {}
        // A movement may not be reused as proof of two differently classified
        // returned batches, even if a writer attempts to alter reviewed evidence.
        $reviewLine=DB::table('hall_inventory_external_out_return_review_lines')
            ->where('receipt_id',$receipt)->where('item_id',$item)->first();
        $this->assertNotNull($reviewLine);
        try {
            DB::transaction(function () use ($reviewLine,$reconciliation,$reviewer,$company,$hall,$loan):void {
                DB::table('hall_inventory_external_out_return_review_lines')
                    ->where('id',$reviewLine->id)
                    ->update(['damaged_movement_id'=>$reviewLine->good_movement_id]);
                $reconciliation->report($reviewer,$company->id,$hall->id,$loan);
                $this->fail('Reused inventory movement accepted for another condition.');
            });
        } catch (\Illuminate\Database\QueryException|ConflictHttpException) {}
        $this->assertSame(1,$reconciliation->report($reviewer,$company->id,$hall->id,$loan)['items'][0]['good']);
        // A manager, even assigned to this exact hall, must not see a
        // private owner financial settlement and physical loss report.
        $this->be($drafter);
        try {
            $reconciliation->report($drafter,$company->id,$hall->id,$loan);
            $this->fail('Manager could read owner-only non-clean reconciliation.');
        }catch(AuthorizationException){}
        $this->be($reviewer);
        $signedReturn=DB::table('hall_inventory_external_out_return_receipts')
            ->where('company_id',$company->id)->where('loan_id',$loan)->first();
        $signedReturnOriginal=Storage::disk('local')->get($signedReturn->evidence_storage_path);
        Storage::disk('local')->put($signedReturn->evidence_storage_path,'tampered return custody');
        try {
            $reconciliation->report($reviewer,$company->id,$hall->id,$loan);
            $this->fail('Tampered partner return proof counted toward non-clean custody.');
        }catch(ConflictHttpException){}
        Storage::disk('local')->put($signedReturn->evidence_storage_path,$signedReturnOriginal);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->assertSee('Non-clean loan reconciliation (read-only)')
            ->assertSee('Physically unreturned: 3')
            ->assertSee('Final non-clean closure: BLOCKED');
        // Physical ownership disposal is independent of counterparty financial
        // recovery and requires a third owner beyond reporter and incident reviewer.
        $writeoffs=app(\App\Services\HallInventoryNoncleanWriteoffService::class);
        try {
            $writeoffs->propose($owner,$company->id,$hall->id,$report,
                (string)Str::uuid(),1,'Written off only after physical damage investigation');
            $this->fail('Default-off physical write-off permitted.');
        } catch (ConflictHttpException) {}
        config()->set('inventory_controls.loan_nonclean_writeoffs_enabled',true);
        $this->be($owner);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->assertSee('Independent abnormal physical write-off approval')
            ->set('noncleanWriteoffIncidentId',$report)
            ->set('noncleanWriteoffQuantity','1')
            ->set('noncleanWriteoffReason','Actual unusable outside-owned table after separate physical review')
            ->call('submitNoncleanWriteoff')->assertHasNoErrors();
        $proposal=DB::table('hall_inventory_nonclean_writeoff_proposals')
            ->where('company_id',$company->id)->where('incident_id',$report)->first();
        $this->assertNotNull($proposal);
        $this->assertSame(0,DB::table('hall_inventory_nonclean_writeoff_decisions')->count());
        $this->be($reviewer);
        try {
            $writeoffs->decide($reviewer,$company->id,$hall->id,$proposal->id,
                (string)Str::uuid(),'approved','I reviewed the original incident too');
            $this->fail('Incident reviewer self-approved physical write-off.');
        } catch (AuthorizationException) {}
        $independent=User::create(['name'=>'Third Independent Owner',
            'email'=>'external-writeoff-third@example.test','password'=>'test-only']);
        $independent->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$independent->id,'role'=>'owner']);
        $this->be($independent);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->set('noncleanWriteoffDecisionProposalId',$proposal->id)
            ->set('noncleanWriteoffDecision','approved')
            ->set('noncleanWriteoffDecisionReason','Independent confirmed physical damage and approved actual owned stock retirement')
            ->call('decideNoncleanWriteoff')->assertHasNoErrors();
        $posted=DB::table('hall_inventory_nonclean_writeoff_decisions')
            ->where('proposal_id',$proposal->id)->first();
        $this->assertSame('approved',$posted->decision);
        $movement=DB::table('hall_inventory_movement_events')->where('id',$posted->movement_id)->first();
        $this->assertSame('writeoff_owned_damaged',$movement->event_kind);
        $after=DB::table('hall_inventory_positions')->where('item_id',$item)->first();
        $this->assertSame(0,(int)$after->owned_damaged);
        $this->assertSame(3,(int)$after->owned_on_loan);
        // A previously documented missing loss also requires its own independent
        // incident decision and third-owner physical custody retirement.
        $this->be($reviewer);
        $decision->decide($reviewer,$company->id,$missing,(string)Str::uuid(),
            'substantiated','Independent physical count confirms three missing units');
        $this->be($owner);
        $missingProposalId=(string)Str::uuid();
        $writeoffs->propose($owner,$company->id,$hall->id,$missing,
            $missingProposalId,3,'Partner did not return three substantiated missing owned tables');
        $this->be($independent);
        $writeoffs->decide($independent,$company->id,$hall->id,$missingProposalId,
            (string)Str::uuid(),'approved','Third owner confirms missing owned stock disposal with evidence');
        $afterMissing=DB::table('hall_inventory_positions')->where('item_id',$item)->first();
        $this->assertSame(0,(int)$afterMissing->owned_on_loan);
        $lossSummary=$reconciliation->report($independent,$company->id,$hall->id,$loan);
        $this->assertSame(3,$lossSummary['items'][0]['writtenOffMissing']);
        $this->assertSame(1,$lossSummary['items'][0]['writtenOffDamaged']);
        $this->assertSame(3,$lossSummary['items'][0]['unreturned']);
        $this->assertSame(0,$lossSummary['items'][0]['onLoan']);
        try {
            $writeoffs->decide($independent,$company->id,$hall->id,$missingProposalId,
                (string)Str::uuid(),'approved','Contradictory duplicate final disposition attempt');
            $this->fail('Second independent write-off decision posted duplicate inventory.');
        } catch (ConflictHttpException) {}
        $this->assertSame(2,DB::table('hall_inventory_nonclean_writeoff_decisions')->count());
        $waiver=app(\App\Services\HallInventoryFinancialWaiverService::class);
        $this->be($owner);
        try {
            $waiver->propose($owner,$company->id,$hall->id,$obligationId,
                (string)Str::uuid(),5000,'Default disabled financial waiver test');
            $this->fail('Default-disabled financial forgiveness accepted.');
        } catch (ConflictHttpException) {}
        config()->set('inventory_controls.loan_financial_waivers_enabled',true);
        $cashJournalCount=DB::table('finance_journals')->count();
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->assertSee('Independent contractual settlement and partial forgiveness')
            ->set('financialWaiverObligationId',$obligationId)
            ->set('financialWaiverAmountMinor','5000')
            ->set('financialWaiverReason','Owner proposed documented goodwill reduction of original debt')
            ->call('submitFinancialWaiver')->assertHasNoErrors();
        $waiverProposal=DB::table('hall_inventory_financial_waiver_proposals')
            ->where('company_id',$company->id)->where('obligation_id',$obligationId)->first();
        $this->assertNotNull($waiverProposal);
        $this->assertSame(5000,$recoveries->outstanding($owner,$company->id,$hall->id,$loan)
            [$obligationId]['waiverPendingMinor']);
        try {
            $waiver->decide($owner,$company->id,$hall->id,$waiverProposal->id,
                (string)Str::uuid(),'approved','Own proposal cannot be self approved');
            $this->fail('Financial owner self-waiver approved.');
        } catch (AuthorizationException) {}
        $this->be($independent);
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->set('financialWaiverDecisionProposalId',$waiverProposal->id)
            ->set('financialWaiverDecision','approved')
            ->set('financialWaiverDecisionReason','Separate owner verifies documented partial contractual forgiveness')
            ->call('decideFinancialWaiver')->assertHasNoErrors();
        $reconciled=$recoveries->outstanding($independent,$company->id,$hall->id,$loan)
            [$obligationId];
        $this->assertSame(5000,$reconciled['waivedMinor']);
        $this->assertSame(20000,$reconciled['remainingMinor']);
        $this->assertSame(0,$reconciled['waiverPendingMinor']);
        $this->assertSame($cashJournalCount,DB::table('finance_journals')->count());
        try {
            $waiver->decide($independent,$company->id,$hall->id,$waiverProposal->id,
                (string)Str::uuid(),'approved','Unauthorized contradictory double approval');
            $this->fail('Second financial waiver decision accepted.');
        } catch (ConflictHttpException) {}
        $this->assertSame(1,DB::table('hall_inventory_financial_waiver_decisions')->count());
        // No operator may mint a numbered non-clean closure through a disabled
        // release gate, even after independent incident and money review.
        $finalClosure=app(\App\Services\HallInventoryNoncleanFinalClosureService::class);
        $this->be($reviewer);
        try {
            $finalClosure->close($reviewer,$company->id,$hall->id,$loan,(string)Str::uuid());
            $this->fail('Non-clean closure bypassed its disabled production feature gate.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_nonclean_final_closure_enabled',true);
        // Enabling only the final gate is never enough: financial waiver and
        // actual evidence-backed recovery controls remain independently OFF.
        try {
            $finalClosure->close($reviewer,$company->id,$hall->id,$loan,(string)Str::uuid());
            $this->fail('Final closure bypassed independent financial release gates.');
        }catch(ConflictHttpException){}
        // Even with individually enabled synthetic release controls, the
        // original missing units and unresolved case prevent final issuance.
        foreach(['loan_nonclean_writeoffs_enabled','loan_recoveries_enabled',
                 'loan_financial_waivers_enabled','loan_authorized_obligations_enabled',
                 'loan_partner_responses_enabled'] as $flag)
            config()->set('inventory_controls.'.$flag,true);
        try {
            $finalClosure->close($reviewer,$company->id,$hall->id,$loan,(string)Str::uuid());
            $this->fail('Unreturned stock and unresolved claims obtained final NCL document.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_nonclean_final_closure_enabled',false);
        $this->assertSame(0,DB::table('hall_inventory_nonclean_final_closures')->count());
        $this->assertSame(0,DB::table('hall_inventory_external_out_clean_closures')->count());
        $this->assertSame(1,DB::table('hall_inventory_financial_recovery_reversals')->count());
        // Finish remaining authorized contractual amount without fabricating cash.
        $this->be($owner);
        $secondWaiver=(string)Str::uuid();
        $waiver->propose($owner,$company->id,$hall->id,$obligationId,$secondWaiver,
            20000,'Signed documented final contractual release of remaining balance');
        $this->be($independent);
        $waiver->decide($independent,$company->id,$hall->id,$secondWaiver,
            (string)Str::uuid(),'approved','Independent verified remainder accepted as final contract release');
        $this->assertSame(0,$recoveries->outstanding($independent,$company->id,$hall->id,$loan)
            [$obligationId]['remainingMinor']);
        $this->be($reviewer);
        config()->set('inventory_controls.loan_nonclean_final_closure_enabled',true);
        $ready=$reconciliation->report($reviewer,$company->id,$hall->id,$loan);
        $this->assertSame([],$ready['closureBlockers']);
        $this->assertFalse($ready['finalClosureAuthorized']); // read-only cannot itself close
        // The independently appointed write-off and waiver approver cannot
        // also serve as the separate final numbered-document signatory.
        $this->be($independent);
        try {
            $finalClosure->close($independent,$company->id,$hall->id,$loan,(string)Str::uuid());
            $this->fail('Prior financial/physical approver signed final NCL certificate.');
        } catch (AuthorizationException) {}
        $this->assertSame(0,DB::table('hall_inventory_nonclean_final_closures')->count());
        $this->be($reviewer);
        // SQL must reject a forged final NCL certificate that pairs a real
        // loan with a DIFFERENT valid hall in the same customer company.
        try {
            DB::transaction(function()use($company,$wrong,$loan,$reviewer):void {
                DB::table('hall_inventory_nonclean_final_closures')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                    'hall_id'=>$wrong->id,'loan_id'=>$loan,
                    'receipt_number'=>99999,
                    'reconciliation_sha256'=>str_repeat('a',64),
                    'pdf_sha256'=>str_repeat('b',64),'pdf_size'=>16,
                    'pdf_storage_path'=>'private/forged-ncl-test.pdf',
                    'closed_by'=>$reviewer->getKey()
                ]);
                $this->fail('SQL accepted a forged foreign-hall NCL certificate.');
            });
        } catch (\Illuminate\Database\QueryException) {}
        $this->assertSame(0,DB::table('hall_inventory_nonclean_final_closures')->count());
        // A valid INTERNAL source-hall loan also cannot enter the NCL series.
        // The serial ledger accepts only actual outside-owned lending loans.
        $internal=(string)Str::uuid();
        DB::table('hall_inventory_loan_drafts')->insert([
            'id'=>$internal,'company_id'=>$company->id,'direction'=>'internal',
            'lending_hall_id'=>$hall->id,'borrowing_hall_id'=>$wrong->id,
            'external_partner_id'=>null,'expected_return_date'=>'2027-12-15',
            'purpose'=>'Synthetic internal loan cannot receive external NCL receipt',
            'request_fingerprint'=>str_repeat('e',64),'created_by'=>$owner->getKey()
        ]);
        try {
            DB::transaction(function()use($company,$hall,$internal,$reviewer):void {
                DB::table('hall_inventory_nonclean_final_closures')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                    'hall_id'=>$hall->id,'loan_id'=>$internal,'receipt_number'=>99998,
                    'reconciliation_sha256'=>str_repeat('a',64),
                    'pdf_sha256'=>str_repeat('b',64),'pdf_size'=>16,
                    'pdf_storage_path'=>'private/forged-internal-ncl-test.pdf',
                    'closed_by'=>$reviewer->getKey()
                ]);
                $this->fail('SQL accepted an internal loan into the external NCL series.');
            });
        } catch (\Illuminate\Database\QueryException) {}
        $this->assertSame(0,DB::table('hall_inventory_nonclean_final_closures')->count());
        // A colliding client-provided closure UUID must NOT overwrite an
        // existing immutable file or remove it on a failed database insert.
        // Simulate a previously issued/orphaned object at the target key.
        $occupiedId=(string)Str::uuid();
        $occupiedPath='private/hall-inventory-nonclean-final/'.$company->id.'/'.$occupiedId.'.pdf';
        $originalBytes="%PDF-1.4\nPreviously issued evidence must remain unchanged\n%%EOF\n";
        Storage::disk('local')->put($occupiedPath,$originalBytes);
        try {
            $finalClosure->close($reviewer,$company->id,$hall->id,$loan,$occupiedId);
            $this->fail('Existing numbered PDF storage key was silently overwritten.');
        } catch (ConflictHttpException) {}
        $this->assertSame($originalBytes,Storage::disk('local')->get($occupiedPath));
        $this->assertSame(0,DB::table('hall_inventory_nonclean_final_closures')->count());
        Storage::disk('local')->delete($occupiedPath);

        $closureId=(string)Str::uuid();
        $closed=$finalClosure->close($reviewer,$company->id,$hall->id,$loan,$closureId);
        $this->assertSame(1,$closed['receiptNumber']);
        $this->assertSame($closed,$finalClosure->close($reviewer,$company->id,$hall->id,$loan,$closureId));
        try {
            $finalClosure->close($reviewer,$company->id,$hall->id,$loan,(string)Str::uuid());
            $this->fail('Second final numbered PDF issued for same original loan.');
        }catch(ConflictHttpException){}
        try {
            $finalClosure->receiptPdf($reviewer,$company->id,$wrong->id,$loan);
            $this->fail('Other hall downloaded private original numbered NCL certificate.');
        }catch(AuthorizationException){}
        $this->be($independent);
        try {
            $finalClosure->close($independent,$company->id,$hall->id,$loan,(string)Str::uuid());
            $this->fail('Inventory write-off approver issued a second NCL receipt.');
        }catch(ConflictHttpException|AuthorizationException){}
        $this->be($reviewer);
        $pdf=$finalClosure->receiptPdf($reviewer,$company->id,$hall->id,$loan);
        $this->assertStringStartsWith('%PDF-1.4',$pdf);
        $this->assertStringContainsString('NCL-1',$pdf);
        $row=DB::table('hall_inventory_nonclean_final_closures')->where('id',$closureId)->first();
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256',$pdf),$row->pdf_sha256);
        $this->assertSame(1,DB::table('hall_inventory_nonclean_final_closures')->count());
        $loanHistory=collect(app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($reviewer,$company->id,$hall->id))->firstWhere('id',$loan);
        $this->assertSame('external_out_nonclean_closed',$loanHistory['status']);
        $this->assertTrue($loanHistory['externalOutNoncleanClosed']);
        $this->assertFalse($loanHistory['overdueWithOutstandingCustody']);

        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('startFinancialClaims',$loan)->assertHasNoErrors()
            ->assertSee('Final non-clean closure: ISSUED')
            ->assertSee('Download existing immutable numbered NCL PDF');
        try {
            DB::transaction(function()use($closureId):void {
                DB::table('hall_inventory_nonclean_final_closures')
                    ->where('id',$closureId)->update(['pdf_sha256'=>str_repeat('0',64)]);
                $this->fail('Final NCL issuance record could be silently modified.');
            });
        }catch(\Illuminate\Database\QueryException){}

        $storedPdf=Storage::disk('local')->get($row->pdf_storage_path);
        Storage::disk('local')->put($row->pdf_storage_path,'altered immutable certificate');
        try{
            $finalClosure->receiptPdf($reviewer,$company->id,$hall->id,$loan);
            $this->fail('Tampered final NCL PDF remained downloadable.');
        }catch(ConflictHttpException){}
        Storage::disk('local')->put($row->pdf_storage_path,$storedPdf);
        $this->assertSame($pdf,$finalClosure->receiptPdf($reviewer,$company->id,$hall->id,$loan));
        // No historical NCL document may be silently superseded by later
        // physical receiving, fresh loss allegations or a new waiver.
        $this->be($witness);
        try {
            app(HallInventoryExternalOutReturnService::class)->receive(
                $witness,$company->id,$hall->id,$loan,
                (string)Str::uuid(),[$item=>1],
                UploadedFile::fake()->createWithContent(
                    'post-final-signed-return.pdf',"%PDF-1.4\n%%EOF"));
            $this->fail('Physical return mutated final NCL custody.');
        }catch(ConflictHttpException){}
        $this->be($owner);
        try {
            $writeoffs->propose($owner,$company->id,$hall->id,$report,
                (string)Str::uuid(),1,'Cannot retire stock twice after final');
            $this->fail('Post-closure stock loss proposal accepted.');
        }catch(ConflictHttpException){}
        try {
            $waiver->propose($owner,$company->id,$hall->id,$obligationId,
                (string)Str::uuid(),1,'Cannot add contractual waiver after closing');
            $this->fail('Post-closure contractual waiver accepted.');
        }catch(ConflictHttpException){}
        $this->be($reviewer);
        // Finalization freezes the documentary and independent review history.
        try {
            $partner->review($reviewer,$company->id,$document,(string)Str::uuid(),
                'document_integrity_confirmed','No reviews may be added after final NCL issuance');
            $this->fail('Late documentary review modified finalized loan history.');
        } catch (ConflictHttpException) {}
        $this->be($owner);
        try {
            $partner->record($owner,$company->id,$claim,(string)Str::uuid(),
                'other_document',null,$partnerPdf());
            $this->fail('Late partner correspondence modified finalized loan history.');
        } catch (ConflictHttpException) {}
        $this->assertSame(2,DB::table('hall_inventory_financial_counterparty_evidence')->count());
        $this->assertSame(2,DB::table('hall_inventory_financial_counterparty_reviews')->count());
        $this->be($reviewer);

        $this->assertSame($cashJournalCount,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_external_out_clean_closures')->count());
    }
}