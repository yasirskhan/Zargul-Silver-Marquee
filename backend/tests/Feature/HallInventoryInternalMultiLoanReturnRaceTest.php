<?php

namespace Tests\Feature;

use App\Models\{Company,CompanyMembership,Hall,HallAssignment,User};
use App\Services\{
    HallInventoryCatalogService,HallInventoryLoanDraftService,
    HallInventoryInternalLoanDispatchService,HallInventoryInternalLoanReceiptService,
    HallInventoryInternalLoanReturnService,HallInventoryInternalLoanReturnAcceptanceService
};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Two genuinely separate original INTERNAL loans share both physical item
 * positions. Their partial return acceptances must serialize across actual
 * PostgreSQL processes; stale revisions never post partial stock or audit.
 */
final class HallInventoryInternalMultiLoanReturnRaceTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown():void
    {
        try { DB::statement('TRUNCATE TABLE companies, users RESTART IDENTITY CASCADE'); }
        finally { parent::tearDown(); }
    }

    private function id():string { return (string) Str::uuid(); }
    private function proof():UploadedFile {
        return UploadedFile::fake()->createWithContent('signed.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }

    public function test_parallel_distinct_loan_partial_acceptances_reject_stale_counts_without_stock_loss():void
    {
        Storage::fake('local');
        foreach (['catalog_enabled','loan_drafts_enabled','loan_dispatch_enabled',
            'loan_receipt_enabled','loan_returns_enabled','loan_return_acceptance_enabled']
            as $name) config()->set('inventory_controls.'.$name,true);

        $co=Company::create(['name'=>'Two-original-return-competition',
            'slug'=>'two-original-return-competition']);
        $source=Hall::create(['company_id'=>$co->id,'name'=>'Source Hall A',
            'slug'=>'return-race-source','capacity'=>150]);
        $borrower=Hall::create(['company_id'=>$co->id,'name'=>'Borrower Hall B',
            'slug'=>'return-race-borrower','capacity'=>150]);

        $staff=[];
        foreach ([['creator','owner'],['dispatch','admin'],['borrower','manager'],
            ['reviewer','owner']] as [$name,$role]) {
            $actor=User::create(['name'=>'Return race '.$name,
                'email'=>'return-race-'.$name.'@example.test','password'=>'test-only']);
            $actor->markEmailAsVerified();
            $member=CompanyMembership::create(['company_id'=>$co->id,
                'user_id'=>$actor->id,'role'=>$role]);
            if ($role==='manager') HallAssignment::create([
                'company_id'=>$co->id,'hall_id'=>$borrower->id,
                'membership_id'=>$member->id]);
            $staff[$name]=$actor;
        }

        $srcItem=$this->id();$dstItem=$this->id();
        $this->be($staff['creator']);
        app(HallInventoryCatalogService::class)->create($staff['creator'],$co->id,$source->id,
            $srcItem,'Shared ceremony chairs','Furniture','piece',1,
            'Source owner counted stock in synthetic PostgreSQL fixture');
        app(HallInventoryCatalogService::class)->create($staff['creator'],$co->id,$borrower->id,
            $dstItem,'Shared ceremony chairs','Furniture','piece',1,
            'Receiving-hall original physical loan mapping');
        DB::table('hall_inventory_positions')->insert([
            'id'=>$this->id(),'company_id'=>$co->id,'hall_id'=>$source->id,
            'item_id'=>$srcItem,'owned_usable'=>12,'revision'=>0,
            'created_at'=>now(),'updated_at'=>now()
        ]);

        $loans=[$this->id(),$this->id()];
        foreach ($loans as $loan) {
            app(HallInventoryLoanDraftService::class)->create($staff['creator'],$co->id,$loan,
                'internal',$source->id,$borrower->id,
                [['itemId'=>$srcItem,'quantity'=>5]],'2027-12-31',
                'Separate original witnessed five-chair loan');
        }
        foreach ($loans as $i=>$loan) {
            $this->be($staff['dispatch']);
            $dispatchId=$this->id();
            $this->assertSame($dispatchId,
                app(HallInventoryInternalLoanDispatchService::class)->dispatch(
                    $staff['dispatch'],$co->id,$loan,$dispatchId,
                    [$srcItem=>$i],$this->proof()));
            $this->be($staff['borrower']);
            $receiptId=$this->id();
            $this->assertSame($receiptId,
                app(HallInventoryInternalLoanReceiptService::class)->receive(
                    $staff['borrower'],$co->id,$loan,$receiptId,
                    [$srcItem=>$dstItem],[$dstItem=>$i],$this->proof()));
        }

        $this->assertSame(2,(int) DB::table('hall_inventory_positions')
            ->where('hall_id',$source->id)->where('item_id',$srcItem)->value('owned_usable'));
        $this->assertSame(10,(int) DB::table('hall_inventory_positions')
            ->where('hall_id',$source->id)->where('item_id',$srcItem)->value('owned_on_loan'));
        $this->assertSame(10,(int) DB::table('hall_inventory_positions')
            ->where('hall_id',$borrower->id)->where('item_id',$dstItem)->value('borrowed_usable'));

        $this->be($staff['borrower']);
        $batches=[$this->id(),$this->id()];
        foreach ($loans as $i=>$loan) {
            $this->assertSame($batches[$i],
                app(HallInventoryInternalLoanReturnService::class)->send(
                    $staff['borrower'],$co->id,$loan,$batches[$i],
                    [$srcItem=>3],[$dstItem=>2+$i],$this->proof()));
        }
        $this->assertSame(4,(int) DB::table('hall_inventory_positions')
            ->where('hall_id',$borrower->id)->where('item_id',$dstItem)->value('borrowed_usable'));
        $this->assertSame(6,(int) DB::table('hall_inventory_positions')
            ->where('hall_id',$borrower->id)->where('item_id',$dstItem)->value('borrowed_transit'));

        $this->be($staff['reviewer']);
        $service=app(HallInventoryInternalLoanReturnAcceptanceService::class);
        $ids=[$this->id(),$this->id()];
        $this->assertTrue(function_exists('pcntl_fork'),'Two-process PostgreSQL race requires pcntl_fork');
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
        $this->assertIsArray($pair);
        [$parentSocket,$childSocket]=$pair;
        $resultPath=tempnam(sys_get_temp_dir(),'multi-original-return-');
        $this->assertNotFalse($resultPath);
        DB::purge();
        $pid=pcntl_fork();
        $this->assertNotSame(-1,$pid);
        if ($pid===0) {
            fclose($parentSocket);
            DB::purge();
            try {
                $backendPid=(int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                fwrite($childSocket,"ready:".$backendPid."\n");
                fgets($childSocket);
                $service->accept($staff['reviewer'],$co->id,$batches[1],$ids[1],
                    [$srcItem=>['good'=>3,'damaged'=>0]],[$srcItem=>2],
                    [$dstItem=>4],$this->proof());
                file_put_contents($resultPath,json_encode(['unexpected'=>'stale acceptance posted'],JSON_THROW_ON_ERROR));
            } catch (ConflictHttpException $e) {
                file_put_contents($resultPath,json_encode(['rejected'=>$e->getMessage()],JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                file_put_contents($resultPath,json_encode(['unexpected'=>get_class($e),
                    'message'=>$e->getMessage()],JSON_THROW_ON_ERROR));
            }
            fclose($childSocket);
            exit(0);
        }
        fclose($childSocket);
        $parentError=null;
        DB::beginTransaction();
        try {
            $hallIds=[$source->id,$borrower->id];
            sort($hallIds,SORT_STRING);
            $this->assertCount(2,DB::table('halls')->where('company_id',$co->id)
                ->whereIn('id',$hallIds)->orderBy('id')->lockForUpdate()->get());
            stream_set_timeout($parentSocket,15);
            $ready=(string)fgets($parentSocket);
            $this->assertMatchesRegularExpression('/^ready:[0-9]+$/',trim($ready));
            $backendPid=(int)substr(trim($ready),6);
            fwrite($parentSocket,"go\n");
            $wait=false;
            for($poll=0;$poll<100;$poll++) {
                if(DB::table('pg_stat_activity')->where('pid',$backendPid)
                    ->value('wait_event_type')==='Lock'){$wait=true;break;}
                usleep(100000);
            }
            $this->assertTrue($wait,'Second original loan did not wait for a real PostgreSQL hall lock.');
            $this->assertSame($ids[0],$service->accept(
                $staff['reviewer'],$co->id,$batches[0],$ids[0],
                [$srcItem=>['good'=>2,'damaged'=>1]],[$srcItem=>2],
                [$dstItem=>4],$this->proof()));
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $parentError=$e;
        } finally {
            fclose($parentSocket);
            pcntl_waitpid($pid,$status);
        }
        if($parentError!==null)throw $parentError;
        $this->assertTrue(pcntl_wifexited($status));
        $child=json_decode((string)file_get_contents($resultPath),true,512,JSON_THROW_ON_ERROR);
        unlink($resultPath);
        $this->assertArrayHasKey('rejected',$child,json_encode($child));
        $this->assertStringContainsString('Changed return position',$child['rejected']);
        $this->assertSame(1,DB::table('hall_inventory_internal_loan_return_acceptances')
            ->where('company_id',$co->id)->count());
        $this->assertSame(1,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$source->id)->where('item_id',$srcItem)->value('owned_damaged'));
        $this->assertSame(3,(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$borrower->id)->where('item_id',$dstItem)->value('borrowed_transit'));

        // The second independent loan can now safely post under fresh revisions.
        $srcRevision=(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$source->id)->where('item_id',$srcItem)->value('revision');
        $dstRevision=(int)DB::table('hall_inventory_positions')
            ->where('hall_id',$borrower->id)->where('item_id',$dstItem)->value('revision');
        $this->assertSame($ids[1],$service->accept($staff['reviewer'],$co->id,
            $batches[1],$ids[1],[$srcItem=>['good'=>3,'damaged'=>0]],
            [$srcItem=>$srcRevision],[$dstItem=>$dstRevision],$this->proof()));
        $this->assertSame($ids[1],$service->accept($staff['reviewer'],$co->id,
            $batches[1],$ids[1],[$srcItem=>['good'=>3,'damaged'=>0]],
            [$srcItem=>$srcRevision],[$dstItem=>$dstRevision],$this->proof()));
        $this->assertSame(2,DB::table('hall_inventory_internal_loan_return_acceptances')
            ->where('company_id',$co->id)->count());
        $srcPos=DB::table('hall_inventory_positions')->where('hall_id',$source->id)
            ->where('item_id',$srcItem)->first();
        $destPos=DB::table('hall_inventory_positions')->where('hall_id',$borrower->id)
            ->where('item_id',$dstItem)->first();
        $this->assertSame(12,(int)$srcPos->owned_usable+(int)$srcPos->owned_on_loan+
            (int)$srcPos->owned_damaged);
        $this->assertSame(7,(int)$srcPos->owned_usable);
        $this->assertSame(4,(int)$srcPos->owned_on_loan);
        $this->assertSame(1,(int)$srcPos->owned_damaged);
        $this->assertSame(4,(int)$destPos->borrowed_usable);
        $this->assertSame(0,(int)$destPos->borrowed_transit);
        $this->assertSame(0,DB::table('finance_journals')->where('company_id',$co->id)->count());
        $this->assertSame(2,DB::table('audit_events')->where('company_id',$co->id)
            ->where('action','hall_inventory.internal_loan_partial_return_accepted')->count());
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_closures')->count());
    }

}
