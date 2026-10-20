<?php

namespace Tests\Feature;

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
use App\Services\HallInventoryInternalLoanReturnAcceptanceService;
use App\Services\HallInventoryCleanLoanClosureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryCleanLoanClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_internal_loan_closure_requires_every_physical_return_and_issues_one_integrity_pinned_pdf():void
    {
        Storage::fake('local');
        $company=Company::create(['name'=>'Clean Closure Test','slug'=>'clean-closure-test']);
        $src=Hall::create(['company_id'=>$company->id,'name'=>'Lender','slug'=>'clean-lender','capacity'=>100]);
        $dst=Hall::create(['company_id'=>$company->id,'name'=>'Borrower','slug'=>'clean-borrower','capacity'=>100]);
        $owner=User::create(['name'=>'Closing Owner','email'=>'clean-owner@example.test','password'=>'test-only']);
        $dispatcher=User::create(['name'=>'Source Dispatcher','email'=>'clean-source@example.test','password'=>'test-only']);
        $receiver=User::create(['name'=>'Destination Receiver','email'=>'clean-receiver@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$dispatcher,'admin'],[$receiver,'manager']] as [$actor,$role]) {
            $actor->markEmailAsVerified();
            $membership=CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$actor->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'membership_id'=>$membership->id,'hall_id'=>$dst->id]);
        }
        foreach(['catalog_enabled','loan_drafts_enabled','loan_dispatch_enabled',
            'loan_receipt_enabled','loan_returns_enabled','loan_return_acceptance_enabled']
            as $key)config()->set('inventory_controls.'.$key,true);
        $this->be($owner);
        $catalog=app(HallInventoryCatalogService::class);
        $sourceItem=(string)Str::uuid();$destItem=(string)Str::uuid();
        $catalog->create($owner,$company->id,$src->id,$sourceItem,
            'Clean dinnerware','Crockery','piece',1,'Clean closure catalog');
        $catalog->create($owner,$company->id,$dst->id,$destItem,
            'Clean dinnerware','Crockery','piece',1,'Borrower catalog');
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,'hall_id'=>$src->id,
            'item_id'=>$sourceItem,'owned_usable'=>3,'revision'=>0,
            'created_at'=>now(),'updated_at'=>now()
        ]);
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,
            $loan,'internal',$src->id,$dst->id,
            [['itemId'=>$sourceItem,'quantity'=>3]],'2027-12-30',
            'Clean closure with independent handovers');
        $file=static fn()=>UploadedFile::fake()->createWithContent(
            'physical.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->be($dispatcher);
        app(HallInventoryInternalLoanDispatchService::class)->dispatch(
            $dispatcher,$company->id,$loan,(string)Str::uuid(),
            [$sourceItem=>0],$file());
        $this->be($receiver);
        app(HallInventoryInternalLoanReceiptService::class)->receive(
            $receiver,$company->id,$loan,(string)Str::uuid(),
            [$sourceItem=>$destItem],[$destItem=>0],$file());
        $closure=app(HallInventoryCleanLoanClosureService::class);
        $closureId=(string)Str::uuid();
        $this->be($owner);
        try {
            $closure->close($owner,$company->id,$loan,$closureId);
            $this->fail('Closed a loan before physical return.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_closures')->count());
        $this->be($receiver);
        $batch=(string)Str::uuid();
        app(HallInventoryInternalLoanReturnService::class)->send(
            $receiver,$company->id,$loan,$batch,[$sourceItem=>3],
            [$destItem=>1],$file());
        $this->be($owner);
        app(HallInventoryInternalLoanReturnAcceptanceService::class)->accept(
            $owner,$company->id,$batch,(string)Str::uuid(),
            [$sourceItem=>['good'=>3,'damaged'=>0]],
            [$sourceItem=>1],[$destItem=>2],$file());
        try {
            $closure->close($owner,$company->id,$loan,$closureId);
            $this->fail('Closed loan while clean closeout release gate was off.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.loan_clean_closure_enabled',true);
        $this->be($dispatcher);
        try {
            $closure->close($dispatcher,$company->id,$loan,$closureId);
            $this->fail('Source dispatcher performed final closeout.');
        }catch(AuthorizationException){}
        $this->be($owner);
        $saved=$closure->close($owner,$company->id,$loan,$closureId);
        $this->assertSame(1,$saved['receiptNumber']);
        $this->assertFalse($saved['financialPosting']);
        $this->assertSame($saved,$closure->close($owner,$company->id,$loan,$closureId));
        $rows=app(\App\Services\HallInventoryLoanDraftReview::class)
            ->recent($owner,$company->id,$src->id);
        $closed=collect($rows)->firstWhere('id',$loan);
        $this->assertSame('clean_loan_closed',$closed['status']);
        $this->assertTrue($closed['returnReconciliation']['loanCompleted']);
        $pdf=$closure->receiptPdf($owner,$company->id,$loan);
        $this->assertStringStartsWith('%PDF-1.4',$pdf);
        $this->assertStringContainsString('Receipt number: 1',$pdf);
        config()->set('inventory_controls.loan_incidents_enabled',true);
        try {
            app(\App\Services\HallInventoryLoanIncidentService::class)
                ->report($owner,$company->id,$loan,(string)Str::uuid(),
                    $sourceItem,'missing',1,'New claim after numbered closeout.');
            $this->fail('Accepted fresh incident after immutable closeout.');
        }catch(ConflictHttpException $e) {
            $this->assertStringContainsString('closure',$e->getMessage());
        }
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(1,DB::table('hall_inventory_internal_loan_closures')->count());
        try {
            $closure->close($owner,$company->id,$loan,(string)Str::uuid());
            $this->fail('Issued a different receipt for already closed loan.');
        }catch(ConflictHttpException){}
        $record=DB::table('hall_inventory_internal_loan_closures')->first();
        Storage::disk('local')->put($record->pdf_storage_path,'tampered');
        try {
            $closure->receiptPdf($owner,$company->id,$loan);
            $this->fail('Served tampered immutable receipt.');
        }catch(ConflictHttpException){}
        $this->be($receiver);
        try {
            $closure->receiptPdf($receiver,$company->id,$loan);
            $this->fail('Destination manager accessed private origin PDF.');
        }catch(AuthorizationException){}
    }
}
