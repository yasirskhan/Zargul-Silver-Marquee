<?php
namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryInternalDiscrepancyService;
use App\Services\HallInventoryInternalPhysicalFindingService;
use App\Services\HallInventoryInternalLossObligationService;
use App\Services\HallInventoryInternalLoanDispatchService;
use App\Services\HallInventoryInternalLoanReceiptService;
use App\Services\HallInventoryLoanDraftService;
use App\Services\HallInventoryLoanIncidentService;
use App\Services\HallInventoryLoanIncidentDecisionService;
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

/** Signed two-hall original-loan evidence never itself moves custody or money. */
class HallInventoryInternalPhysicalFindingTest extends TestCase {
    use RefreshDatabase;
    private function pdf():UploadedFile {
        return UploadedFile::fake()->createWithContent('signed-evidence.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }
    private function fixture():array {
        Storage::fake('local');
        $company=Company::create(['name'=>'Original loan source proof','slug'=>'original-loan-source-proof']);
        $source=Hall::create(['company_id'=>$company->id,'name'=>'Source evidence hall','slug'=>'source-evidence-hall','capacity'=>100]);
        $borrower=Hall::create(['company_id'=>$company->id,'name'=>'Borrower evidence hall','slug'=>'borrower-evidence-hall','capacity'=>100]);
        $owner=User::create(['name'=>'Source original owner','email'=>'original-source-owner@example.test','password'=>'test-only']);
        $admin=User::create(['name'=>'Independent source reviewer','email'=>'original-source-admin@example.test','password'=>'test-only']);
        $manager=User::create(['name'=>'Signed loan borrower','email'=>'original-loan-borrower@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$admin,'admin'],[$manager,'manager']] as [$u,$role]) {
            $u->markEmailAsVerified();
            $member=CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create(['company_id'=>$company->id,'hall_id'=>$borrower->id,'membership_id'=>$member->id]);
        }
        foreach(['catalog_enabled','loan_drafts_enabled','loan_dispatch_enabled','loan_receipt_enabled',
            'loan_returns_enabled','loan_return_acceptance_enabled','loan_incidents_enabled',
            'loan_incident_reviews_enabled','internal_discrepancy_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $this->be($owner);
        $item=(string)Str::uuid();$mapped=(string)Str::uuid();
        $catalog=app(HallInventoryCatalogService::class);
        $catalog->create($owner,$company->id,$source->id,$item,
            'Physical source proof tray','Furniture','piece',1,'Signed original source catalog');
        $catalog->create($owner,$company->id,$borrower->id,$mapped,
            'Physical source proof tray','Furniture','piece',1,'Signed mapped borrower catalog');
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,'hall_id'=>$source->id,
            'item_id'=>$item,'owned_usable'=>30,'revision'=>0,'created_at'=>now(),'updated_at'=>now()
        ]);
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,$loan,
            'internal',$source->id,$borrower->id,[['itemId'=>$item,'quantity'=>12]],
            '2027-12-30','Signed cross-hall physical discrepancy test');
        $this->be($admin);
        app(HallInventoryInternalLoanDispatchService::class)->dispatch(
            $admin,$company->id,$loan,(string)Str::uuid(),[$item=>0],$this->pdf());
        $this->be($manager);
        app(HallInventoryInternalLoanReceiptService::class)->receive(
            $manager,$company->id,$loan,(string)Str::uuid(),[$item=>$mapped],[$mapped=>0],$this->pdf());
        $statement=(string)Str::uuid();
        app(HallInventoryInternalDiscrepancyService::class)->submit(
            $manager,$company->id,$borrower->id,$loan,$item,$statement,'missing',3,
            'Three source trays are physically unaccounted for.', $this->pdf());
        $this->be($owner);
        app(HallInventoryInternalDiscrepancyService::class)->review(
            $owner,$company->id,$source->id,$statement,(string)Str::uuid(),
            'corroborated','Original borrower signed statement independently corroborated.');
        $incident=(string)Str::uuid();
        app(HallInventoryLoanIncidentService::class)->report(
            $owner,$company->id,$loan,$incident,$item,'missing',3,
            'Three trays unreturned in original lending hall.');
        $this->be($admin);
        app(HallInventoryLoanIncidentDecisionService::class)->decide(
            $admin,$company->id,$incident,(string)Str::uuid(),'substantiated',
            'Original lending hall confirms three physically unreturned trays.');
        return compact('company','source','borrower','owner','admin','manager','item','mapped','loan','statement','incident');
    }
    public function test_correlated_proofs_are_immutable_private_independently_reviewed_and_do_not_clear_physical_stock():void {
        $f=$this->fixture();extract($f);
        $service=app(HallInventoryInternalPhysicalFindingService::class);
        $finding=(string)Str::uuid();
        try {
            $service->propose($admin,$company->id,$source->id,$loan,$statement,$incident,
                $finding,'Independent source physical count supports discrepancy.',$this->pdf());
            $this->fail('Default-OFF new reconciliation flag was bypassed.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.internal_physical_finding_enabled',true);
        $this->assertSame($finding,$service->propose($admin,$company->id,$source->id,
            $loan,$statement,$incident,$finding,
            'Independent source physical count supports discrepancy.',$this->pdf()));
        $this->assertSame($finding,$service->propose($admin,$company->id,$source->id,
            $loan,$statement,$incident,$finding,
            'Independent source physical count supports discrepancy.',$this->pdf()));
        try {
            $service->propose($admin,$company->id,$source->id,$loan,$statement,$incident,
                $finding,'Forged changed source physical statement must fail.',$this->pdf());
            $this->fail('Conflicting retry changed immutable original evidence.');
        }catch(ConflictHttpException){}
        $row=DB::table('hall_inventory_internal_physical_findings')->where('id',$finding)->first();
        $this->assertSame(12,(int)$row->outstanding_snapshot);
        $this->assertSame(3,(int)$row->quantity);
        $this->assertSame(1,DB::table('hall_inventory_internal_physical_findings')->count());
        try {
            $service->propose($admin,$company->id,$source->id,$loan,$statement,$incident,
                (string)Str::uuid(),'Second identical physical claim must not allocate.',$this->pdf());
            $this->fail('Duplicate source incident reused.');
        }catch(\Illuminate\Database\QueryException|ConflictHttpException){}
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$source->id)->call('startInternalDiscrepancyReview',$loan)
            ->assertHasNoErrors()->assertSee('Original loan physical discrepancy findings')
            ->assertSee($finding);
        try {
            $service->decide($admin,$company->id,$source->id,$finding,(string)Str::uuid(),
                'confirmed_outstanding','Self-decision must be prohibited.');
            $this->fail('Independent physical finding self approval allowed.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $decision=(string)Str::uuid();$reason='Exact signed independent borrower and lender proof corroborates unreturned custody.';
        $this->assertSame($decision,$service->decide($owner,$company->id,$source->id,
            $finding,$decision,'confirmed_outstanding',$reason));
        $this->assertSame($decision,$service->decide($owner,$company->id,$source->id,
            $finding,$decision,'confirmed_outstanding',$reason));
        try {
            $service->decide($owner,$company->id,$source->id,$finding,(string)Str::uuid(),
                'rejected','Second decision cannot erase physical evidence.');
            $this->fail('Duplicate decision changed immutable finding.');
        }catch(ConflictHttpException){}
        $this->assertSame('confirmed_outstanding',$service->history(
            $owner,$company->id,$source->id,$loan,true)[0]['decision']);
        $result=$service->sourceProof($owner,$company->id,$source->id,$finding,true);
        $this->assertSame('application/pdf',$result['mime']);
        $this->assertSame(1,DB::table('audit_events')->where('action',
            'hall_inventory.internal_physical_finding_source_proof_read')->count());
        $this->assertSame(12,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_on_loan'));
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_closures')->count());
        $this->assertSame(0,DB::table('hall_inventory_nonclean_final_closures')->count());
        $this->be($manager);
        $this->assertCount(1,$service->history($manager,$company->id,$borrower->id,$loan,false));
        $this->assertSame($result['bytes'],$service->sourceProof(
            $manager,$company->id,$borrower->id,$finding,false)['bytes']);
        try {
            $service->sourceProof($manager,$company->id,$source->id,$finding,true);
            $this->fail('Borrowing-hall manager read lender private source scope.');
        }catch(AuthorizationException){}
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$manager->id)->update(['is_active'=>false]);
        try {
            $service->history($manager,$company->id,$borrower->id,$loan,false);
            $this->fail('Revoked borrower continued reading original proof.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $disk=Storage::disk('local');$bytes=$disk->get($row->source_proof_path);
        $disk->put($row->source_proof_path,'tampered source file');
        try {
            $service->history($owner,$company->id,$source->id,$loan,true);
            $this->fail('Tampered source proof was accepted.');
        }catch(ConflictHttpException){}
        try {
            $service->sourceProof($owner,$company->id,$source->id,$finding,true);
            $this->fail('Tampered source proof was downloaded.');
        }catch(ConflictHttpException){}
        $disk->put($row->source_proof_path,$bytes);
        $this->assertCount(1,$service->history($owner,$company->id,$source->id,$loan,true));
        $this->assertSame(12,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$source->id)->where('item_id',$item)->value('owned_on_loan'));
    }
    public function test_later_independently_accepted_good_returns_block_stale_physical_finding_confirmation():void {
        $f=$this->fixture();extract($f);
        config()->set('inventory_controls.internal_physical_finding_enabled',true);
        $svc=app(HallInventoryInternalPhysicalFindingService::class);
        $id=(string)Str::uuid();
        $this->assertSame($id,$svc->propose($admin,$company->id,$source->id,$loan,
            $statement,$incident,$id,'Witnessed source item remains unreturned pending physical checks.',$this->pdf()));
        $this->be($manager);
        $batch=(string)Str::uuid();
        app(\App\Services\HallInventoryInternalLoanReturnService::class)->send(
            $manager,$company->id,$loan,$batch,[$item=>10],[$mapped=>1],$this->pdf());
        // Shipment is IN TRANSIT. A lender cannot treat it as a
        // separately witnessed good return or as missing retirement.
        $this->be($owner);
        $accept=(string)Str::uuid();
        app(\App\Services\HallInventoryInternalLoanReturnAcceptanceService::class)->accept(
            $owner,$company->id,$batch,$accept,[$item=>['good'=>10,'damaged'=>0]],
            [$item=>1],[$mapped=>2],$this->pdf());
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_on_loan'));
        $live=$svc->history($owner,$company->id,$source->id,$loan,true);
        $this->assertSame(2,$live[0]['currentPhysicalOutstanding']);
        $this->assertFalse($live[0]['historicalFindingStillFullyOutstanding']);
        try {
            $svc->decide($owner,$company->id,$source->id,$id,(string)Str::uuid(),
                'confirmed_outstanding','Do not confirm historical three-unit discrepancy after ten actual good returns.');
            $this->fail('A stale unreturned finding was confirmed after true witnessed returns reduced open custody.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_internal_physical_finding_decisions')
            ->where('company_id',$company->id)->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_nonclean_final_closures')->count());
    }

    public function test_signed_correction_requires_third_source_owner_and_preserves_original_stock_and_proof():void {
        $f=$this->fixture();extract($f);
        config()->set('inventory_controls.internal_physical_finding_enabled',true);
        $findingSvc=app(HallInventoryInternalPhysicalFindingService::class);
        $correctionSvc=app(\App\Services\HallInventoryInternalFindingCorrectionService::class);
        $finding=(string)Str::uuid();
        $findingSvc->propose($admin,$company->id,$source->id,$loan,$statement,$incident,
            $finding,'Actual source incident and borrower evidence fully agreed.',$this->pdf());
        $this->be($owner);
        $findingSvc->decide($owner,$company->id,$source->id,$finding,(string)Str::uuid(),
            'confirmed_outstanding','Original source owner independently confirms still-open custody.');
        $third=User::create(['name'=>'Third source evidence owner',
            'email'=>'original-third-owner@example.test','password'=>'test-only']);
        $third->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$third->id,'role'=>'owner']);
        $this->be($admin);
        $correction=(string)Str::uuid();
        $reason='Later independently reviewed signed proof rebuts this historical count.';
        $this->assertSame($correction,$correctionSvc->propose(
            $admin,$company->id,$source->id,$finding,$correction,$reason,$this->pdf()));
        $this->assertSame($correction,$correctionSvc->propose(
            $admin,$company->id,$source->id,$finding,$correction,$reason,$this->pdf()));
        try {
            $correctionSvc->propose($admin,$company->id,$source->id,$finding,
                $correction,'Changed signed correction must never silently overwrite.',$this->pdf());
            $this->fail('Changed immutable signed correction replay allowed.');
        }catch(ConflictHttpException){}
        $this->be($owner);
        try {
            $correctionSvc->review($owner,$company->id,$source->id,$correction,
                (string)Str::uuid(),'superseded',
                'Previous physical reviewer cannot approve their own finding correction.');
            $this->fail('Original physical reviewer approved its own correction.');
        }catch(AuthorizationException){}
        $this->be($admin);
        try {
            $correctionSvc->review($admin,$company->id,$source->id,$correction,
                (string)Str::uuid(),'superseded',
                'Original signed source investigator must not approve this correction.');
            $this->fail('Original evidence author self-reviewed correction.');
        }catch(AuthorizationException){}
        $this->be($third);
        $decision=(string)Str::uuid();
        $this->assertSame($decision,$correctionSvc->review($third,$company->id,$source->id,
            $correction,$decision,'superseded',
            'Third owner independently accepts correction of documentary evidence only.'));
        $this->assertSame($decision,$correctionSvc->review($third,$company->id,$source->id,
            $correction,$decision,'superseded',
            'Third owner independently accepts correction of documentary evidence only.'));
        try {
            $correctionSvc->review($third,$company->id,$source->id,$correction,
                (string)Str::uuid(),'rejected',
                'Completed immutable correction cannot be overwritten.');
            $this->fail('Independent correction decision overwritten.');
        }catch(ConflictHttpException){}
        $current=$findingSvc->history($third,$company->id,$source->id,$loan,true);
        $this->assertTrue($current[0]['supersededByIndependentCorrection']);
        $this->assertSame('confirmed_outstanding',$current[0]['decision']);
        $this->assertSame($correction,$current[0]['correctionId']);
        $this->assertSame('superseded',$correctionSvc->history(
            $third,$company->id,$source->id,$loan)[0]['decision']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(HallInventory::class)->set('companyId',$company->id)
            ->set('hallId',$source->id)->call('startInternalDiscrepancyReview',$loan)
            ->assertHasNoErrors()->assertSee('Correct historical physical discrepancy finding')
            ->assertSee('Historical original finding superseded')
            ->assertSee($correction);
        $proof=$correctionSvc->signedProof($third,$company->id,$source->id,$correction);
        $this->assertSame('application/pdf',$proof['mime']);
        $row=DB::table('hall_inventory_internal_finding_corrections')
            ->where('company_id',$company->id)->where('id',$correction)->first();
        $disk=Storage::disk('local');$original=$disk->get($row->proof_path);
        $disk->put($row->proof_path,'tampered independently signed source correction');
        try {
            $correctionSvc->history($third,$company->id,$source->id,$loan);
            $this->fail('Tampered immutable source correction remained readable.');
        }catch(ConflictHttpException){}
        try {
            $correctionSvc->signedProof($third,$company->id,$source->id,$correction);
            $this->fail('Tampered source correction was downloaded.');
        }catch(ConflictHttpException){}
        $disk->put($row->proof_path,$original);
        $this->assertCount(1,$correctionSvc->history($third,$company->id,$source->id,$loan));
        $this->assertSame(12,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$source->id)->where('item_id',$item)->value('owned_on_loan'));
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_nonclean_final_closures')->count());
        $this->assertSame(1,DB::table('hall_inventory_internal_physical_finding_decisions')->count());
        $this->assertSame(1,DB::table('hall_inventory_internal_finding_correction_reviews')->count());
    }

    public function test_unrelated_loan_or_forged_source_hall_cannot_use_original_signatures():void {
        $f=$this->fixture();extract($f);
        config()->set('inventory_controls.internal_physical_finding_enabled',true);
        $service=app(HallInventoryInternalPhysicalFindingService::class);
        try {
            $service->propose($admin,$company->id,$source->id,(string)Str::uuid(),
                $statement,$incident,(string)Str::uuid(),
                'Cannot reuse other original loan evidence.',$this->pdf());
            $this->fail('Cross-loan original evidence accepted.');
        }catch(AuthorizationException|ConflictHttpException){}
        try {
            $service->propose($admin,$company->id,$borrower->id,$loan,
                $statement,$incident,(string)Str::uuid(),
                'Borrowing hall cannot forge original source witness.',$this->pdf());
            $this->fail('Forged originating source hall accepted.');
        }catch(AuthorizationException){}
        $this->assertSame(0,DB::table('hall_inventory_internal_physical_findings')->count());
    }

    public function test_confirmed_internal_finding_grounds_provisional_obligation_without_money_or_stock():void {
        $f=$this->fixture();extract($f);
        config()->set('inventory_controls.internal_physical_finding_enabled',true);
        $findings=app(HallInventoryInternalPhysicalFindingService::class);
        $finding=(string)Str::uuid();
        $findings->propose($admin,$company->id,$source->id,$loan,$statement,$incident,$finding,
            'Independent source physical count supports discrepancy.',$this->pdf());
        $this->be($owner);
        $findings->decide($owner,$company->id,$source->id,$finding,(string)Str::uuid(),
            'confirmed_outstanding','Independent confirmation of the original physical discrepancy.');
        $approver=User::create(['name'=>'Independent internal obligation owner',
            'email'=>'internal-obligation-owner@example.test','password'=>'test-only']);
        $approver->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$approver->id,'role'=>'owner']);
        config()->set('inventory_controls.internal_loss_obligation_enabled',true);
        $obligations=app(HallInventoryInternalLossObligationService::class);
        $this->be($admin);
        $obligation=(string)Str::uuid();
        $this->assertSame($obligation,$obligations->propose($admin,$company->id,$source->id,
            $finding,$obligation,'PKR',5000,
            'Confirmed three-unit internal discrepancy grounds the provisional obligation.'));
        $row=DB::table('hall_inventory_internal_loss_obligations')->where('id',$obligation)->first();
        $this->assertSame(3,(int)$row->quantity_basis);
        $this->assertSame(5000,(int)$row->amount_minor);
        try {
            $obligations->decide($admin,$company->id,$source->id,$obligation,
                (string)Str::uuid(),'approved','Proposer cannot approve their own amount.');
            $this->fail('Internal obligation proposer self-approved.');
        }catch(AuthorizationException){}
        $this->be($owner);
        try {
            $obligations->decide($owner,$company->id,$source->id,$obligation,
                (string)Str::uuid(),'approved','Finding reviewer cannot approve the amount.');
            $this->fail('Finding reviewer approved the financial amount.');
        }catch(AuthorizationException){}
        $this->be($approver);
        $decision=(string)Str::uuid();
        $this->assertSame($decision,$obligations->decide($approver,$company->id,$source->id,
            $obligation,$decision,'approved',
            'Independent source owner approves the provisional amount without any money.'));
        $this->assertSame($decision,$obligations->decide($approver,$company->id,$source->id,
            $obligation,$decision,'approved',
            'Independent source owner approves the provisional amount without any money.'));
        try {
            $obligations->decide($approver,$company->id,$source->id,$obligation,
                (string)Str::uuid(),'rejected','Conflicting replay attempts to reverse the decision.');
            $this->fail('Internal obligation decision was mutable through replay.');
        }catch(ConflictHttpException){}
        $history=collect($obligations->recent($approver,$company->id,$source->id))
            ->firstWhere('id',$obligation);
        $this->assertNotNull($history);
        $this->assertSame('approved',$history['decision']);
        $this->assertSame(3,$history['quantityBasis']);
        $this->assertSame(5000,$history['amountMinor']);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(12,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_on_loan'));
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_closures')->count());
        $this->assertSame(0,DB::table('hall_inventory_external_in_clean_closures')->count());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($approver);
        Livewire::test(HallInventory::class)
            ->set('companyId',$company->id)->set('hallId',$source->id)
            ->call('startInternalDiscrepancyReview',$loan)->assertHasNoErrors()
            ->assertSee('INTERNAL PROVISIONAL LOSS OBLIGATION')
            ->assertSee('basis 3 — APPROVED');
    }
}
