<?php
namespace Tests\Feature;

use App\Models\{Company,CompanyMembership,Hall,HallAssignment,User};
use App\Services\{HallInventoryCatalogService,HallInventoryLoanDraftService,HallInventoryExternalOutDispatchService,HallInventoryExternalOutReturnService,HallInventoryLoanIncidentService,HallInventoryLoanIncidentDecisionService,HallInventoryFinancialClaimService,HallInventoryFinancialCounterpartyEvidenceService,HallInventoryFinancialObligationService,HallInventoryNoncleanWriteoffService,HallInventoryFinancialWaiverService,HallInventoryNoncleanReconciliationService,HallInventoryNoncleanFinalClosureService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Two separate genuinely eligible loans; no direct forged final ledger rows. */
final class HallInventoryNoncleanMultiLoanIssuanceTest extends TestCase
{
    use RefreshDatabase;
    private function id():string {return (string)Str::uuid();}
    private function pdf():UploadedFile {return UploadedFile::fake()->createWithContent('signed.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");}
    public function test_two_reconciled_loans_have_unique_company_numbers_and_original_pdf_bytes():void
    {
        Storage::fake('local');
        $co=Company::create(['name'=>'NCL Two-Loan Venue','slug'=>'ncl-two-loan-venue']);
        $hall=Hall::create(['company_id'=>$co->id,'name'=>'Original Hall','slug'=>'original-hall','capacity'=>100]);
        $staff=[];
        foreach(['drafter'=>'manager','witness'=>'manager','reporter'=>'owner','reviewer'=>'owner','independent'=>'owner'] as $role=>$kind) {
            $u=User::create(['name'=>'NCL '.$role,'email'=>'ncl-multi-'.$role.'@example.test','password'=>'test-only']);
            $u->markEmailAsVerified();
            $m=CompanyMembership::create(['company_id'=>$co->id,'user_id'=>$u->id,'role'=>$kind]);
            if($kind==='manager')HallAssignment::create(['company_id'=>$co->id,'hall_id'=>$hall->id,'membership_id'=>$m->id]);
            $staff[$role]=$u;
        }
        foreach(['catalog_enabled','loan_drafts_enabled','external_out_dispatch_enabled','external_out_return_enabled',
            'loan_incidents_enabled','loan_incident_reviews_enabled','loan_financial_claims_enabled',
            'loan_partner_responses_enabled','loan_authorized_obligations_enabled','loan_nonclean_reconciliation_enabled',
            'loan_nonclean_writeoffs_enabled','loan_financial_waivers_enabled','loan_recoveries_enabled',
            'loan_nonclean_final_closure_enabled'] as $flag)config()->set('inventory_controls.'.$flag,true);
        $final=app(HallInventoryNoncleanFinalClosureService::class);
        $issued=[];
        for($i=0;$i<2;$i++) {
            $item=$this->id();$loan=$this->id();
            $this->be($staff['drafter']);
            app(HallInventoryCatalogService::class)->create($staff['drafter'],$co->id,$hall->id,$item,
                'Damaged outside table '.$i,'Furniture','piece',1,'Independent serial item');
            DB::table('hall_inventory_positions')->insert(['id'=>$this->id(),'company_id'=>$co->id,
                'hall_id'=>$hall->id,'item_id'=>$item,'owned_usable'=>1,'created_at'=>now(),'updated_at'=>now()]);
            app(HallInventoryLoanDraftService::class)->create($staff['drafter'],$co->id,$loan,
                'external_out',$hall->id,null,[['itemId'=>$item,'quantity'=>1]],'2027-12-15',
                'Distinct damaged loan '.$i,'Outside partner '.$i,'Signed outside handover');
            $this->be($staff['witness']);
            app(HallInventoryExternalOutDispatchService::class)->record($staff['witness'],$co->id,$hall->id,
                $loan,$this->id(),$this->pdf(),[$item=>1]);
            $this->be($staff['reporter']);
            app(HallInventoryExternalOutDispatchService::class)->approve($staff['reporter'],$co->id,$hall->id,$loan,$this->id());
            $this->be($staff['witness']);$returned=$this->id();
            app(HallInventoryExternalOutReturnService::class)->receive($staff['witness'],$co->id,$hall->id,
                $loan,$returned,[$item=>1],$this->pdf());
            $this->be($staff['reporter']);
            app(HallInventoryExternalOutReturnService::class)->review($staff['reporter'],$co->id,$hall->id,
                $returned,$this->id(),[$item=>['good'=>0,'damaged'=>1,'unresolved'=>0]]);
            $incident=$this->id();
            app(HallInventoryLoanIncidentService::class)->report($staff['reporter'],$co->id,$loan,
                $incident,$item,'damage',1,'Separately witnessed irreparable damage');
            $this->be($staff['reviewer']);
            app(HallInventoryLoanIncidentDecisionService::class)->decide($staff['reviewer'],$co->id,
                $incident,$this->id(),'substantiated','Independently confirmed physical damage');
            $this->be($staff['reporter']);$claim=$this->id();
            app(HallInventoryFinancialClaimService::class)->propose($staff['reporter'],$co->id,
                $incident,$claim,'USD',1000,'Documented damaged item assessment');
            $this->be($staff['reviewer']);
            app(HallInventoryFinancialClaimService::class)->decide($staff['reviewer'],$co->id,$claim,
                $this->id(),'approved_for_collection','Independent amount assessment only');
            $this->be($staff['reporter']);$evidence=$this->id();
            app(HallInventoryFinancialCounterpartyEvidenceService::class)->record($staff['reporter'],$co->id,
                $claim,$evidence,'document_says_accepts',null,$this->pdf());
            $this->be($staff['reviewer']);
            app(HallInventoryFinancialCounterpartyEvidenceService::class)->review($staff['reviewer'],$co->id,
                $evidence,$this->id(),'document_integrity_confirmed','Independent signed-document integrity check');
            $this->be($staff['reporter']);$obligation=$this->id();
            app(HallInventoryFinancialObligationService::class)->propose($staff['reporter'],$co->id,
                $claim,$evidence,$obligation,1000,'verified_callback','Recorded signed callback '.$i,
                'Original partner personally confirmed liability');
            $this->be($staff['reviewer']);
            app(HallInventoryFinancialObligationService::class)->decide($staff['reviewer'],$co->id,
                $obligation,$this->id(),'authorized','Independent collectible obligation assessment');
            $this->be($staff['reporter']);$writeoff=$this->id();
            app(HallInventoryNoncleanWriteoffService::class)->propose($staff['reporter'],$co->id,$hall->id,
                $incident,$writeoff,1,'Approved actual unusable owned-stock retirement');
            $this->be($staff['independent']);
            app(HallInventoryNoncleanWriteoffService::class)->decide($staff['independent'],$co->id,$hall->id,
                $writeoff,$this->id(),'approved','Distinct owner confirms irreversible physical disposition');
            $this->be($staff['reporter']);$waiver=$this->id();
            app(HallInventoryFinancialWaiverService::class)->propose($staff['reporter'],$co->id,$hall->id,
                $obligation,$waiver,1000,'Separately signed release of final contractual debt');
            $this->be($staff['independent']);
            app(HallInventoryFinancialWaiverService::class)->decide($staff['independent'],$co->id,$hall->id,
                $waiver,$this->id(),'approved','Independently authorized final financial waiver');
            $this->be($staff['reviewer']);
            $snapshot=app(HallInventoryNoncleanReconciliationService::class)->report($staff['reviewer'],$co->id,$hall->id,$loan);
            $this->assertSame([],$snapshot['closureBlockers']);
            $this->assertSame(0,$snapshot['obligations'][$obligation]['remainingMinor']);
            $movementCount=DB::table('hall_inventory_movement_events')->count();
            $journalCount=DB::table('finance_journals')->count();
            $id=$this->id();$issuedResult=$final->close($staff['reviewer'],$co->id,$hall->id,$loan,$id);
            $this->assertSame($i+1,$issuedResult['receiptNumber']);
            $bytes=$final->receiptPdf($staff['reviewer'],$co->id,$hall->id,$loan);
            $this->assertStringContainsString('NCL-'.($i+1),$bytes);
            $this->assertStringContainsString('Loan '.$loan,$bytes);
            $this->assertSame($issuedResult,$final->close($staff['reviewer'],$co->id,$hall->id,$loan,$id));
            $this->assertSame($bytes,$final->receiptPdf($staff['reviewer'],$co->id,$hall->id,$loan));
            $this->assertSame($movementCount,DB::table('hall_inventory_movement_events')->count());
            $this->assertSame($journalCount,DB::table('finance_journals')->count());
            $issued[]=['id'=>$id,'loan'=>$loan,'bytes'=>$bytes];
        }
        $rows=DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$co->id)->orderBy('receipt_number')->get();
        $this->assertCount(2,$rows);
        foreach($rows as $i=>$row) {
            $this->assertSame($i+1,(int)$row->receipt_number);
            $this->assertSame($issued[$i]['id'],$row->id);
            $this->assertSame($issued[$i]['loan'],$row->loan_id);
            $this->assertSame(hash('sha256',$issued[$i]['bytes']),$row->pdf_sha256);
            $this->assertSame($issued[$i]['bytes'],Storage::disk('local')->get($row->pdf_storage_path));
        }
        $this->assertNotSame($issued[0]['bytes'],$issued[1]['bytes']);
    }
}
