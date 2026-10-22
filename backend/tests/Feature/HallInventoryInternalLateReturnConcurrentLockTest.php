<?php
namespace Tests\Feature;

use App\Models\{Company,CompanyMembership,Hall,HallAssignment,User};
use App\Services\{HallInventoryCatalogService,HallInventoryLoanDraftService,
    HallInventoryInternalLoanDispatchService,HallInventoryInternalLoanReceiptService,
    HallInventoryInternalLoanReturnService,HallInventoryInternalLoanReturnAcceptanceService,
    HallInventoryInternalLateReturnService,HallInventoryInternalLoanReturnReview};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A committed real two-connection PostgreSQL lock race against the original
 * internal loan. Never rely on same-connection nesting to certify a race.
 */
final class HallInventoryInternalLateReturnConcurrentLockTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown():void {
        try { DB::statement('TRUNCATE TABLE companies, users RESTART IDENTITY CASCADE'); }
        finally { parent::tearDown(); }
    }
    private function id():string{return (string)Str::uuid();}
    private function pdf():UploadedFile {
        return UploadedFile::fake()->createWithContent('signed.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }
    public function test_separate_postgres_connection_seals_late_physical_return_original_loan_and_prevents_double_post():void
    {
        Storage::fake('local');
        $co=Company::create(['name'=>'Late return serial lock','slug'=>'late-return-serial-lock']);
        $src=Hall::create(['company_id'=>$co->id,'name'=>'Origin evidence','slug'=>'late-lock-source','capacity'=>80]);
        $dest=Hall::create(['company_id'=>$co->id,'name'=>'Borrower evidence','slug'=>'late-lock-dest','capacity'=>80]);
        $owner=User::create(['name'=>'Original source count','email'=>'late-lock-owner@example.test','password'=>'test-only']);
        $admin=User::create(['name'=>'Independent new source count','email'=>'late-lock-admin@example.test','password'=>'test-only']);
        $manager=User::create(['name'=>'Borrowing physical returner','email'=>'late-lock-manager@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$admin,'admin'],[$manager,'manager']] as [$u,$role]) {
            $u->markEmailAsVerified();
            $member=CompanyMembership::create(['company_id'=>$co->id,'user_id'=>$u->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create(['company_id'=>$co->id,'hall_id'=>$dest->id,'membership_id'=>$member->id]);
        }
        foreach(['catalog_enabled','loan_drafts_enabled','loan_dispatch_enabled','loan_receipt_enabled',
            'loan_returns_enabled','loan_return_acceptance_enabled','internal_late_return_enabled'] as $flag)
            config()->set('inventory_controls.'.$flag,true);
        $this->be($owner);
        $item=$this->id();$mapped=$this->id();
        app(HallInventoryCatalogService::class)->create($owner,$co->id,$src->id,$item,
            'Signed late return lock unit','Furniture','piece',1,'Source lock physical stock');
        app(HallInventoryCatalogService::class)->create($owner,$co->id,$dest->id,$mapped,
            'Signed late return lock unit','Furniture','piece',1,'Mapped borrower physical stock');
        DB::table('hall_inventory_positions')->insert([
            'id'=>$this->id(),'company_id'=>$co->id,'hall_id'=>$src->id,'item_id'=>$item,
            'owned_usable'=>12,'revision'=>0,'created_at'=>now(),'updated_at'=>now()
        ]);
        $loan=$this->id();
        app(HallInventoryLoanDraftService::class)->create($owner,$co->id,$loan,'internal',$src->id,
            $dest->id,[['itemId'=>$item,'quantity'=>5]],'2027-12-31','Signed original five-unit partial review');
        $this->be($admin);
        app(HallInventoryInternalLoanDispatchService::class)->dispatch($admin,$co->id,
            $loan,$this->id(),[$item=>0],$this->pdf());
        $this->be($manager);
        app(HallInventoryInternalLoanReceiptService::class)->receive($manager,$co->id,
            $loan,$this->id(),[$item=>$mapped],[$mapped=>0],$this->pdf());
        $batch=$this->id();
        app(HallInventoryInternalLoanReturnService::class)->send($manager,$co->id,$loan,$batch,
            [$item=>5],[$mapped=>1],$this->pdf());
        $this->be($owner);
        app(HallInventoryInternalLoanReturnAcceptanceService::class)->accept($owner,$co->id,
            $batch,$this->id(),[$item=>['good'=>3,'damaged'=>1]],[$item=>1],[$mapped=>2],$this->pdf());
        $this->be($admin);
        $svc=app(HallInventoryInternalLateReturnService::class);
        $lateId=$this->id();
        config(['database.connections.late_return_second_pgsql'=>config('database.connections.pgsql')]);
        DB::purge('late_return_second_pgsql');
        $other=DB::connection('late_return_second_pgsql');
        $this->assertNotSame(DB::connection()->getPdo(),$other->getPdo());
        $other->beginTransaction();
        try {
            $held=$other->table('hall_inventory_loan_drafts')
                ->where('company_id',$co->id)->where('id',$loan)
                ->lockForUpdate()->first(['id']);
            $this->assertSame($loan,$held->id);
            DB::statement("SET lock_timeout = '300ms'");
            try {
                $svc->record($admin,$co->id,$batch,$lateId,$item,1,0,3,3,$this->pdf());
                $this->fail('An original loan in a separate live PostgreSQL lock allowed late source stock to post.');
            }catch(QueryException $error){
                $this->assertStringContainsString('lock timeout',strtolower($error->getMessage()));
            }
            $this->assertSame(0,$other->table('hall_inventory_internal_late_returns')
                ->where('company_id',$co->id)->count());
            $this->assertSame(1,(int)$other->table('hall_inventory_positions')
                ->where('hall_id',$dest->id)->where('item_id',$mapped)->value('borrowed_transit'));
            $this->assertSame(0,$other->table('finance_journals')->count());
        }finally{
            DB::statement('SET lock_timeout TO DEFAULT');
            $other->rollBack();
            DB::disconnect('late_return_second_pgsql');
        }
        $this->assertSame($lateId,$svc->record($admin,$co->id,$batch,$lateId,
            $item,1,0,3,3,$this->pdf()));
        $this->assertSame($lateId,$svc->record($admin,$co->id,$batch,$lateId,
            $item,1,0,3,3,$this->pdf()));
        $this->assertSame(1,DB::table('hall_inventory_internal_late_returns')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$dest->id)->where('item_id',$mapped)->value('borrowed_transit'));
        $this->assertSame(3,(int)DB::table('hall_inventory_movement_events')
            ->where('reason','like','%later%')->count());
        $read=app(HallInventoryInternalLoanReturnReview::class)
            ->abnormalSourceLines($admin,$co->id,$src->id,$loan);
        $this->assertSame(4,$read[0]['good']);
        $this->assertSame(0,$read[0]['unresolved']);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_closures')->count());
    }
}
