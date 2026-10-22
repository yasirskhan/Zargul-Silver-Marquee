<?php
namespace Tests\Feature;

use App\Models\{Company,CompanyMembership,Hall,User};
use App\Services\{HallInventoryCatalogService,HallInventoryLoanDraftService,HallInventoryInternalLoanDispatchService};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Two real PostgreSQL processes competing for the last source unit.
 * These committed genuine loans must never both dispatch the same item.
 */
final class HallInventoryInternalDispatchContentionTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown():void {
        try { DB::statement('TRUNCATE TABLE companies, users RESTART IDENTITY CASCADE'); }
        finally { parent::tearDown(); }
    }

    private function id():string {return (string)Str::uuid();}
    private function proof():UploadedFile {
        return UploadedFile::fake()->createWithContent('witness.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }

    public function test_two_live_loans_cannot_dispatch_last_unit_twice():void {
        Storage::fake('local');
        config()->set('inventory_controls.catalog_enabled',true);
        config()->set('inventory_controls.loan_drafts_enabled',true);
        config()->set('inventory_controls.loan_dispatch_enabled',true);
        $company=Company::create(['name'=>'Parallel Dispatch Company','slug'=>'parallel-dispatch-company']);
        $source=Hall::create(['company_id'=>$company->id,
            'name'=>'Parallel Source','slug'=>'parallel-source','capacity'=>100]);
        $destination=Hall::create(['company_id'=>$company->id,
            'name'=>'Parallel Borrower','slug'=>'parallel-borrower','capacity'=>100]);
        $creator=User::create(['name'=>'Separate Draft Maker',
            'email'=>'concurrency-draft@example.test','password'=>'test-only']);
        $dispatcher=User::create(['name'=>'Independent Source Dispatcher',
            'email'=>'concurrency-dispatcher@example.test','password'=>'test-only']);
        foreach([[$creator,'owner'],[$dispatcher,'admin']] as [$actor,$role]) {
            $actor->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$actor->id,'role'=>$role]);
        }
        $this->be($creator);
        $item=$this->id();
        app(HallInventoryCatalogService::class)->create($creator,$company->id,$source->id,
            $item,'Last physical tray','Furniture','piece',1,'Committed inventory');
        DB::table('hall_inventory_positions')->insert([
            'id'=>$this->id(),'company_id'=>$company->id,'hall_id'=>$source->id,
            'item_id'=>$item,'owned_usable'=>1,'revision'=>0,
            'created_at'=>now(),'updated_at'=>now()
        ]);
        $loanA=$this->id();$loanB=$this->id();
        foreach([$loanA,$loanB] as $loan) {
            app(HallInventoryLoanDraftService::class)->create($creator,$company->id,$loan,
                'internal',$source->id,$destination->id,
                [['itemId'=>$item,'quantity'=>1]],'2027-06-10',
                'Independent eligible loan competing for one available unit');
        }
        $dispatchA=$this->id();$dispatchB=$this->id();
        $proofA=$this->proof();$proofB=$this->proof();
        $this->be($dispatcher);
        $service=app(HallInventoryInternalLoanDispatchService::class);
        $this->assertTrue(function_exists('pcntl_fork'),'PostgreSQL process race requires pcntl_fork');
        DB::purge();
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
        $this->assertIsArray($pair);
        [$parentSocket,$childSocket]=$pair;
        $resultFile=tempnam(sys_get_temp_dir(),'dispatch-two-process-');
        $this->assertNotFalse($resultFile);
        $pid=pcntl_fork();
        $this->assertNotSame(-1,$pid);
        if($pid===0) {
            fclose($parentSocket);
            DB::purge();
            $backendPid=(int)DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            fwrite($childSocket,"ready:".$backendPid."\n");
            fgets($childSocket);
            try {
                $result=$service->dispatch($dispatcher,$company->id,$loanB,
                    $dispatchB,[$item=>0],$proofB);
                file_put_contents($resultFile,json_encode(['dispatched'=>$result],JSON_THROW_ON_ERROR));
            }catch(ConflictHttpException $e) {
                file_put_contents($resultFile,json_encode(['rejected'=>$e->getMessage()],JSON_THROW_ON_ERROR));
            }catch(\Throwable $e) {
                file_put_contents($resultFile,json_encode(['unexpected'=>get_class($e),
                    'message'=>$e->getMessage()],JSON_THROW_ON_ERROR));
            }
            fclose($childSocket);
            exit(0);
        }
        fclose($childSocket);
        $parentError=null;
        DB::beginTransaction();
        try {
            // Hold the actual two hall locks in the same deterministic order
            // as the service while the second process reaches its lock wait.
            $ids=[$source->id,$destination->id];
            sort($ids,SORT_STRING);
            $locked=DB::table('halls')->where('company_id',$company->id)
                ->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get();
            $this->assertCount(2,$locked);
            stream_set_timeout($parentSocket,15);
            $line=(string)fgets($parentSocket);
            $this->assertMatchesRegularExpression('/^ready:[0-9]+$/',trim($line));
            $childBackendPid=(int)trim(substr($line,6));
            fwrite($parentSocket,"go\n");
            $sawWait=false;
            for($poll=0;$poll<100;$poll++) {
                if(DB::table('pg_stat_activity')->where('pid',$childBackendPid)
                    ->value('wait_event_type')==='Lock') {$sawWait=true;break;}
                usleep(100000);
            }
            $this->assertTrue($sawWait,'Competing eligible loan never waited on real PostgreSQL hall lock.');
            $this->assertSame($dispatchA,$service->dispatch($dispatcher,$company->id,
                $loanA,$dispatchA,[$item=>0],$proofA));
            DB::commit();
        }catch(\Throwable $e) {
            DB::rollBack();
            $parentError=$e;
        }finally {
            fclose($parentSocket);
            pcntl_waitpid($pid,$status);
        }
        if($parentError!==null)throw $parentError;
        $this->assertTrue(pcntl_wifexited($status));
        $child=json_decode((string)file_get_contents($resultFile),true,512,JSON_THROW_ON_ERROR);
        unlink($resultFile);
        $this->assertArrayHasKey('rejected',$child,json_encode($child));
        $this->assertStringContainsString('Stale or insufficient',$child['rejected']);
        $position=DB::table('hall_inventory_positions')->where('company_id',$company->id)
            ->where('hall_id',$source->id)->where('item_id',$item)->first();
        $this->assertSame(0,(int)$position->owned_usable);
        $this->assertSame(1,(int)$position->owned_on_loan);
        $this->assertSame(1,(int)$position->revision);
        $this->assertSame(1,DB::table('hall_inventory_loan_dispatches')->count());
        $this->assertSame(1,DB::table('hall_inventory_loan_dispatch_lines')->count());
        $this->assertSame(1,DB::table('hall_inventory_movement_events')
            ->where('event_kind','dispatch_owned_loan')->count());
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.internal_loan_source_dispatched')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertFalse(Storage::disk('local')->exists(
            'private/hall-loan-handover/'.$company->id.'/'.$loanB.'/'.$dispatchB));
        $this->assertSame($dispatchA,$service->dispatch($dispatcher,$company->id,
            $loanA,$dispatchA,[$item=>0],$proofA));
        $this->assertSame(1,DB::table('hall_inventory_movement_events')
            ->where('event_kind','dispatch_owned_loan')->count());
    }
}
