<?php
namespace Tests\Feature;

use App\Models\{Company,CompanyMembership,Hall,User};
use App\Services\{HallInventoryCatalogService,HallInventoryLoanDraftService,HallInventoryInternalPhysicalFindingService};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/** A genuinely separate PostgreSQL connection must not bypass original loan sealing. */
final class HallInventoryInternalFindingConcurrentLoanLockTest extends TestCase {
    use DatabaseMigrations;
    protected function tearDown():void {
        try {
            DB::statement('TRUNCATE TABLE companies, users RESTART IDENTITY CASCADE');
        } finally { parent::tearDown(); }
    }
    public function test_signed_source_physical_finding_waits_for_original_loan_lock():void {
        Storage::fake('local');
        $company=Company::create(['name'=>'Finding original lock venue','slug'=>'finding-original-lock-venue']);
        $src=Hall::create(['company_id'=>$company->id,'name'=>'Loan source','slug'=>'lock-source','capacity'=>90]);
        $dest=Hall::create(['company_id'=>$company->id,'name'=>'Loan borrower','slug'=>'lock-borrower','capacity'=>90]);
        $owner=User::create(['name'=>'Independent lock owner','email'=>'finding-loan-lock@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $this->be($owner);
        foreach(['catalog_enabled','loan_drafts_enabled','loan_dispatch_enabled','loan_receipt_enabled',
            'loan_returns_enabled','loan_return_acceptance_enabled','loan_incidents_enabled',
            'loan_incident_reviews_enabled','internal_discrepancy_enabled',
            'internal_physical_finding_enabled'] as $key)config()->set('inventory_controls.'.$key,true);
        $item=(string)Str::uuid();$loan=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($owner,$company->id,$src->id,$item,
            'Physical finding lock trays','Furniture','piece',1,'Origin loan committed lock fixture');
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,'hall_id'=>$src->id,
            'item_id'=>$item,'owned_usable'=>4,'revision'=>0,
            'created_at'=>now(),'updated_at'=>now()
        ]);
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,$loan,
            'internal',$src->id,$dest->id,[['itemId'=>$item,'quantity'=>3]],
            '2027-12-31','Original signed evidence lock race');
        // Not merely two transactions on one connection: the committed
        // original row is held by an independent PostgreSQL backend.
        config(['database.connections.finding_second_pgsql'=>config('database.connections.pgsql')]);
        DB::purge('finding_second_pgsql');
        $other=DB::connection('finding_second_pgsql');
        $this->assertNotSame(DB::connection()->getPdo(),$other->getPdo());
        $other->beginTransaction();
        try {
            $locked=$other->table('hall_inventory_loan_drafts')
                ->where('company_id',$company->id)->where('id',$loan)
                ->lockForUpdate()->first(['id']);
            $this->assertSame($loan,$locked->id);
            DB::statement("SET lock_timeout = '300ms'");
            $proof=UploadedFile::fake()->createWithContent('signed.pdf',
                "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
            try {
                app(HallInventoryInternalPhysicalFindingService::class)->propose(
                    $owner,$company->id,$src->id,$loan,(string)Str::uuid(),(string)Str::uuid(),
                    (string)Str::uuid(),'A concurrent source loan witness cannot bypass original custody row lock.',$proof);
                $this->fail('A signed discrepancy was posted while another PostgreSQL connection sealed the loan.');
            }catch(QueryException $e) {
                $this->assertStringContainsString('lock timeout',strtolower($e->getMessage()));
            }
            $this->assertSame(0,$other->table('hall_inventory_internal_physical_findings')
                ->where('company_id',$company->id)->count());
        } finally {
            DB::statement('SET lock_timeout TO DEFAULT');
            $other->rollBack();
            DB::disconnect('finding_second_pgsql');
        }
        // Release restores ordinary access, but missing actual borrower and
        // lender signed evidence still cannot manufacture a finding.
        try {
            app(HallInventoryInternalPhysicalFindingService::class)->propose(
                $owner,$company->id,$src->id,$loan,(string)Str::uuid(),(string)Str::uuid(),
                (string)Str::uuid(),'Missing signed witnesses must never be enough for resolution.',
                UploadedFile::fake()->createWithContent('signed.pdf',
                    "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"));
            $this->fail('A physical finding was created without two independently reviewed signed sources.');
        }catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_internal_physical_findings')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
    }
}
