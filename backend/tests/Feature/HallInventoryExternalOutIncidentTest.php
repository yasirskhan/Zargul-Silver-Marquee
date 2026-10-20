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
            ->assertSee('Financial journal posted: NO');
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
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_closures')->count());
        $p=DB::table('hall_inventory_positions')->where('company_id',$company->id)
            ->where('hall_id',$hall->id)->where('item_id',$item)->first();
        $this->assertSame(3,(int)$p->owned_on_loan);
        $this->assertSame(6,(int)$p->owned_usable);
        $this->assertSame(1,(int)$p->owned_damaged);
        $this->assertSame(2,DB::table('hall_inventory_loan_incident_reports')->count());
    }
}