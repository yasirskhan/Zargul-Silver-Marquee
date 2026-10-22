<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryInternalAbnormalClosureService;
use App\Services\HallInventoryInternalDiscrepancyService;
use App\Services\HallInventoryInternalLoanDispatchService;
use App\Services\HallInventoryInternalLoanReceiptService;
use App\Services\HallInventoryInternalLossObligationService;
use App\Services\HallInventoryInternalLossSettlementService;
use App\Services\HallInventoryInternalPhysicalFindingService;
use App\Services\HallInventoryLoanDraftService;
use App\Services\HallInventoryLoanIncidentDecisionService;
use App\Services\HallInventoryLoanIncidentService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** ADR-0009 Option A: authorized write-off and numbered certificate, no company journal. */
class HallInventoryInternalAbnormalClosureTest extends TestCase {
    use RefreshDatabase;
    private function pdf():UploadedFile {
        return UploadedFile::fake()->createWithContent('internal-abnormal.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }
    public function test_authorized_writeoff_retires_only_confirmed_units_and_issues_numbered_certificate():void {
        Storage::fake('local');
        $company=Company::create(['name'=>'Internal abnormal closure','slug'=>'internal-abnormal-closure']);
        $source=Hall::create(['company_id'=>$company->id,'name'=>'Internal closure source','slug'=>'internal-closure-source','capacity'=>100]);
        $borrower=Hall::create(['company_id'=>$company->id,'name'=>'Internal closure borrower','slug'=>'internal-closure-borrower','capacity'=>100]);
        $owner=User::create(['name'=>'Source owner','email'=>'closure-owner@example.test','password'=>'test-only']);
        $admin=User::create(['name'=>'Source admin','email'=>'closure-admin@example.test','password'=>'test-only']);
        $manager=User::create(['name'=>'Borrower manager','email'=>'closure-manager@example.test','password'=>'test-only']);
        $closer=User::create(['name'=>'Settlement reviewer','email'=>'closure-reviewer@example.test','password'=>'test-only']);
        $authorizer=User::create(['name'=>'Independent write-off owner','email'=>'closure-authorizer@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$admin,'admin'],[$closer,'owner'],[$authorizer,'owner'],[$manager,'manager']] as [$u,$role]) {
            $u->markEmailAsVerified();
            $member=CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create(['company_id'=>$company->id,'hall_id'=>$borrower->id,'membership_id'=>$member->id]);
        }
        foreach(['catalog_enabled','loan_drafts_enabled','loan_dispatch_enabled','loan_receipt_enabled',
            'loan_returns_enabled','loan_return_acceptance_enabled','loan_incidents_enabled',
            'loan_incident_reviews_enabled','internal_discrepancy_enabled',
            'internal_physical_finding_enabled','internal_loss_obligation_enabled',
            'internal_loss_settlement_enabled','internal_abnormal_writeoff_enabled',
            'internal_abnormal_closure_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $this->be($owner);
        $item=(string)Str::uuid();$mapped=(string)Str::uuid();
        $catalog=app(HallInventoryCatalogService::class);
        $catalog->create($owner,$company->id,$source->id,$item,'Closure test tray','Furniture','piece',1,'Internal closure fixture');
        $catalog->create($owner,$company->id,$borrower->id,$mapped,'Closure test tray','Furniture','piece',1,'Internal mapped fixture');
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,'hall_id'=>$source->id,
            'item_id'=>$item,'owned_usable'=>1,'revision'=>0,'created_at'=>now(),'updated_at'=>now()
        ]);
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,$loan,'internal',
            $source->id,$borrower->id,[['itemId'=>$item,'quantity'=>1]],'2027-12-30','Internal closure fixture');
        $this->be($admin);
        app(HallInventoryInternalLoanDispatchService::class)->dispatch($admin,$company->id,$loan,
            (string)Str::uuid(),[$item=>0],$this->pdf());
        $this->be($manager);
        app(HallInventoryInternalLoanReceiptService::class)->receive($manager,$company->id,$loan,
            (string)Str::uuid(),[$item=>$mapped],[$mapped=>0],$this->pdf());
        $statement=(string)Str::uuid();
        app(HallInventoryInternalDiscrepancyService::class)->submit($manager,$company->id,
            $borrower->id,$loan,$item,$statement,'missing',1,
            'The single closure unit is physically unaccounted for.',$this->pdf());
        $this->be($owner);
        app(HallInventoryInternalDiscrepancyService::class)->review($owner,$company->id,$source->id,
            $statement,(string)Str::uuid(),'corroborated','Original borrower statement independently corroborated.');
        $incident=(string)Str::uuid();
        app(HallInventoryLoanIncidentService::class)->report($owner,$company->id,$loan,$incident,
            $item,'missing',1,'The single closure unit is unreturned.');
        $this->be($admin);
        app(HallInventoryLoanIncidentDecisionService::class)->decide($admin,$company->id,$incident,
            (string)Str::uuid(),'substantiated','Lender confirms the single unit was never returned.');
        $finding=(string)Str::uuid();
        app(HallInventoryInternalPhysicalFindingService::class)->propose($admin,$company->id,$source->id,
            $loan,$statement,$incident,$finding,'Exact signed evidence covers the single missing unit.',$this->pdf());
        $this->be($owner);
        app(HallInventoryInternalPhysicalFindingService::class)->decide($owner,$company->id,$source->id,
            $finding,(string)Str::uuid(),'confirmed_outstanding','Independent confirmation of the single missing unit.');
        $obligation=(string)Str::uuid();
        $this->be($admin);
        app(HallInventoryInternalLossObligationService::class)->propose($admin,$company->id,$source->id,
            $finding,$obligation,'PKR',1000,'Confirmed single-unit internal loss obligation.');
        $this->be($closer);
        app(HallInventoryInternalLossObligationService::class)->decide($closer,$company->id,$source->id,
            $obligation,(string)Str::uuid(),'approved','Independent approval of the single-unit obligation.');
        $settlement=(string)Str::uuid();
        $this->be($owner);
        app(HallInventoryInternalLossSettlementService::class)->record($owner,$company->id,$source->id,
            $obligation,$settlement,1000,'PKR','bank','INTERNAL-CLOSE-1','2026-10-22',
            UploadedFile::fake()->createWithContent('internal-close-proof.pdf',"%PDF-1.4\nproof\n%%EOF"));
        $this->be($closer);
        app(HallInventoryInternalLossSettlementService::class)->review($closer,$company->id,$source->id,
            $settlement,(string)Str::uuid(),'verified','Independent verification of the single-unit settlement.');
        $service=app(HallInventoryInternalAbnormalClosureService::class);
        $authorization=(string)Str::uuid();
        $this->be($authorizer);
        $this->assertSame($authorization,$service->authorize($authorizer,$company->id,$source->id,
            $loan,$authorization,'Independent authorization to write off the confirmed missing unit.'));
        $this->assertSame(1,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_on_loan'));
        $closureId=(string)Str::uuid();
        $closed=$service->close($authorizer,$company->id,$source->id,$loan,$closureId);
        $this->assertSame('IAC-1',$closed['serialNumber']);
        $this->assertSame(1,$closed['writeoffQuantity']);
        $this->assertSame(1000,$closed['amountMinor']);
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_on_loan'));
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_usable'));
        $this->assertSame(0,DB::table('finance_journals')->count());
        $pdf=Storage::disk('local')->get('private/hall-internal-abnormal/'.$company->id.
            '/'.$loan.'/'.$closureId.'.pdf');
        $this->assertStringStartsWith('%PDF-1.4',$pdf);
        $this->assertSame(hash('sha256',$pdf),$closed['pdfSha256']);
        $this->assertSame($closed['serialNumber'],$service->close($authorizer,$company->id,
            $source->id,$loan,$closureId)['serialNumber']);
        try {
            $service->close($authorizer,$company->id,$source->id,$loan,(string)Str::uuid());
            $this->fail('Internal abnormal closure was mutable through a second identifier.');
        }catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException){}
        try {
            $service->authorize($owner,$company->id,$source->id,$loan,(string)Str::uuid(),
                'Settlement recorder cannot authorize the related write-off.');
            $this->fail('Settlement recorder authorized the write-off.');
        }catch(AuthorizationException|ConflictHttpException){}
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($authorizer);
        Livewire::test(\App\Filament\Pages\HallInventory::class)
            ->set('companyId',$company->id)->set('hallId',$source->id)
            ->call('startInternalDiscrepancyReview',$loan)
            ->assertSee('INTERNAL ABNORMAL FINAL CERTIFICATE IAC-1');
    }
}
