<?php
namespace Tests\Feature;

use App\Models\{Company,CompanyMembership,Hall,HallAssignment,User};
use App\Services\{
    HallInventoryCatalogService,HallInventoryLoanDraftService,
    HallInventoryExternalBorrowedIntakeService,HallInventoryExternalBorrowedReturnService,
    HallInventoryExternalBorrowedDiscrepancyService,
    HallInventoryExternalBorrowedDiscrepancyClearanceService,
    HallInventoryExternalInLossObligationService,HallInventoryExternalInLossSettlementService,
    HallInventoryExternalInNoncleanReadinessService,
    HallInventoryExternalInNoncleanClosureService
};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/** Real separately connected PostgreSQL issuers racing on two original loans. */
final class HallInventoryExternalInConcurrentClosureTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown():void {
        try { DB::statement('TRUNCATE TABLE companies, users RESTART IDENTITY CASCADE'); }
        finally { parent::tearDown(); }
    }
    private function id():string {return (string)Str::uuid();}
    private function proof(string $marker):UploadedFile {
        return UploadedFile::fake()->createWithContent($marker.'.pdf',
            "%PDF-1.4\n".$marker."\n%%EOF");
    }

    private function readyOriginal(Company $company,Hall $hall,string $item,int $index,
        User $drafter,User $handler,User $owner,User $reviewer,User $approver):string {
        $loan=$this->id();
        $this->be($drafter);
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,$loan,
            'external_in',null,$hall->id,[['itemId'=>$item,'quantity'=>3]],
            '2027-12-31','Concurrent final issuance original custody '.$index,
            'Partner '.$index,'Separately signed partner');
        $this->be($handler);
        app(HallInventoryExternalBorrowedIntakeService::class)->record(
            $handler,$company->id,$hall->id,$loan,$this->id(),
            $this->proof('intake-'.$index),[$item=>3]);
        $this->be($owner);
        app(HallInventoryExternalBorrowedIntakeService::class)->accept(
            $owner,$company->id,$hall->id,$loan,$this->id());
        $this->be($handler);
        $batch=$this->id();
        app(HallInventoryExternalBorrowedReturnService::class)->dispatch(
            $handler,$company->id,$hall->id,$loan,$batch,[$item=>3],
            $this->proof('return-'.$index));
        $this->be($owner);
        $discrepancy=$this->id();
        app(HallInventoryExternalBorrowedDiscrepancyService::class)->report(
            $owner,$company->id,$hall->id,$batch,$discrepancy,
            [$item=>['received'=>2,'damaged'=>0]],
            'Partner received two of three units on original '.$index,
            $this->proof('partner-short-'.$index));
        $this->be($reviewer);
        app(HallInventoryExternalBorrowedDiscrepancyService::class)->decide(
            $reviewer,$company->id,$hall->id,$discrepancy,$this->id(),
            'substantiated','Independent discrepancy review for original '.$index);
        app(HallInventoryExternalBorrowedDiscrepancyClearanceService::class)->clear(
            $reviewer,$company->id,$hall->id,$discrepancy,$this->id());
        $obligation=$this->id();
        app(HallInventoryExternalInLossObligationService::class)->propose(
            $reviewer,$company->id,$hall->id,$discrepancy,$obligation,'PKR',1000,
            'Original '.$index.' independently recognized partner shortfall');
        $this->be($approver);
        app(HallInventoryExternalInLossObligationService::class)->decide(
            $approver,$company->id,$hall->id,$obligation,$this->id(),
            'approved','Independent financial approval of original '.$index);
        $this->be($reviewer);
        $settlement=$this->id();
        app(HallInventoryExternalInLossSettlementService::class)->record(
            $reviewer,$company->id,$hall->id,$obligation,$settlement,1000,'PKR',
            'bank','BANK-ENCL-RACE-'.$index,'2026-10-22',
            $this->proof('payment-'.$index));
        $this->be($approver);
        app(HallInventoryExternalInLossSettlementService::class)->review(
            $approver,$company->id,$hall->id,$settlement,$this->id(),
            'verified','Independent matched partner payment for original '.$index);
        $assessment=app(HallInventoryExternalInNoncleanReadinessService::class)
            ->assess($approver,$company->id,$hall->id,$loan);
        $this->assertTrue($assessment['ready'],json_encode($assessment['blockers']));
        $this->assertSame(1,$assessment['missingInTransit']);
        return $loan;
    }

    public function test_two_original_loans_serialize_independent_encl_numbered_issuance():void {
        Storage::fake('local');
        foreach([
            'catalog_enabled','loan_drafts_enabled',
            'external_borrowed_intake_enabled','external_borrowed_return_enabled',
            'external_borrowed_discrepancy_enabled',
            'external_borrowed_discrepancy_clearance_enabled',
            'external_in_loss_obligation_enabled','external_in_loss_settlement_enabled',
            'external_in_nonclean_closure_enabled'
        ] as $name)config()->set('inventory_controls.'.$name,true);
        config()->set('finance_controls.posting_enabled',true);
        $company=Company::create(['name'=>'Concurrent ENCL Company',
            'slug'=>'concurrent-encl-company']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Concurrent ENCL Hall',
            'slug'=>'concurrent-encl-hall','capacity'=>100]);
        $people=[];
        foreach([['drafter','manager'],['handler','manager'],['owner','owner'],
            ['reviewer','owner'],['approver','owner']] as [$name,$role]) {
            $actor=User::create(['name'=>'ENCL '.$name,
                'email'=>'encl-race-'.$name.'@example.test','password'=>'test-only']);
            $actor->markEmailAsVerified();
            $membership=CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$actor->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'hall_id'=>$hall->id,'membership_id'=>$membership->id]);
            $people[$name]=$actor;
        }
        ['drafter'=>$drafter,'handler'=>$handler,'owner'=>$owner,
            'reviewer'=>$reviewer,'approver'=>$approver]=$people;
        $item=$this->id();
        $this->be($drafter);
        app(HallInventoryCatalogService::class)->create($drafter,$company->id,$hall->id,
            $item,'Shared borrowed unit','External partner','piece',1,
            'Concurrent original borrowed-custody certification');
        $loanA=$this->readyOriginal($company,$hall,$item,1,
            $drafter,$handler,$owner,$reviewer,$approver);
        $loanB=$this->readyOriginal($company,$hall,$item,2,
            $drafter,$handler,$owner,$reviewer,$approver);
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$hall->id)
            ->where('item_id',$item)->value('borrowed_transit'));
        $this->assertSame(2,DB::table('finance_journals')
            ->where('company_id',$company->id)->count());
        $firstId=$this->id();$secondId=$this->id();
        $service=app(HallInventoryExternalInNoncleanClosureService::class);
        $this->be($approver);
        $this->assertTrue(function_exists('pcntl_fork'),'ENCL issuance requires process support');
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
        $this->assertIsArray($pair);
        [$parentSocket,$childSocket]=$pair;
        $resultFile=tempnam(sys_get_temp_dir(),'encl-parallel-');
        $this->assertNotFalse($resultFile);
        // The last DB connection must be purged before forking, otherwise
        // child PHP cleanup can close the inherited parent PostgreSQL socket.
        DB::purge();
        $pid=pcntl_fork();
        $this->assertNotSame(-1,$pid);
        if($pid===0) {
            fclose($parentSocket);
            DB::purge();
            try {
                $backendPid=(int)DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                fwrite($childSocket,"ready:".$backendPid."\n");
                fgets($childSocket);
                $result=$service->close($approver,$company->id,$hall->id,$loanB,$secondId);
                file_put_contents($resultFile,json_encode(['result'=>$result],JSON_THROW_ON_ERROR));
            }catch(\Throwable $e) {
                file_put_contents($resultFile,json_encode(['error'=>get_class($e),
                    'message'=>$e->getMessage()],JSON_THROW_ON_ERROR));
            }
            fclose($childSocket);
            exit(0);
        }
        fclose($childSocket);
        $parentError=null;
        DB::beginTransaction();
        try {
            $this->assertNotNull(DB::table('companies')->where('id',$company->id)
                ->lockForUpdate()->first(['id']));
            stream_set_timeout($parentSocket,15);
            $ready=(string)fgets($parentSocket);
            $this->assertMatchesRegularExpression('/^ready:[0-9]+$/',trim($ready));
            $childPid=(int)trim(substr($ready,6));
            fwrite($parentSocket,"go\n");
            $wait=false;
            for($i=0;$i<100;$i++) {
                if(DB::table('pg_stat_activity')->where('pid',$childPid)
                    ->value('wait_event_type')==='Lock'){$wait=true;break;}
                usleep(100000);
            }
            $this->assertTrue($wait,'ENCL issuer did not wait on PostgreSQL company serial lock.');
            $first=$service->close($approver,$company->id,$hall->id,$loanA,$firstId);
            DB::commit();
        }catch(\Throwable $e) {
            DB::rollBack();$parentError=$e;
        }finally {
            fclose($parentSocket);
            pcntl_waitpid($pid,$status);
        }
        if($parentError!==null)throw $parentError;
        $this->assertTrue(pcntl_wifexited($status));
        $child=json_decode((string)file_get_contents($resultFile),true,512,JSON_THROW_ON_ERROR);
        unlink($resultFile);
        $this->assertArrayNotHasKey('error',$child,json_encode($child));
        $this->assertSame('ENCL-1',$first['serialNumber']);
        $this->assertSame('ENCL-2',$child['result']['serialNumber']);
        $this->assertSame($child['result']['id'],$secondId);
        $this->assertSame(2,DB::table('hall_inventory_external_in_nonclean_closures')
            ->where('company_id',$company->id)->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('company_id',$company->id)->where('item_id',$item)
            ->where('event_kind','writeoff_borrowed_transit')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$hall->id)
            ->where('item_id',$item)->value('borrowed_transit'));
        $this->assertSame(2,DB::table('finance_journals')
            ->where('company_id',$company->id)->count());
        $this->assertSame(2,DB::table('audit_events')
            ->where('company_id',$company->id)
            ->where('action','hall_inventory.external_in_nonclean_closed')->count());
        foreach([$first,$child['result']] as $certificate) {
            $row=DB::table('hall_inventory_external_in_nonclean_closures')
                ->where('id',$certificate['id'])->first();
            $this->assertNotNull($row);
            $pdf=Storage::disk('local')->get((string)$row->pdf_path);
            $this->assertSame((int)$row->pdf_size,strlen($pdf));
            $this->assertSame((string)$row->pdf_sha256,hash('sha256',$pdf));
        }
        $this->assertSame('ENCL-2',$service->close($approver,$company->id,$hall->id,
            $loanB,$secondId)['serialNumber']);
        $this->assertSame(2,DB::table('hall_inventory_movement_events')
            ->where('company_id',$company->id)->where('item_id',$item)
            ->where('event_kind','writeoff_borrowed_transit')->count());
    }

    /**
     * Competing real PostgreSQL backends: a settled partner payment reversal
     * must wait for issuance of its original loan's immutable ENCL certificate.
     * This is a synchronized cross-service race, not a sequential denial mock.
     */
    public function test_parallel_partner_payment_reversal_cannot_unsettle_issued_encl():void {
        Storage::fake('local');
        foreach([
            'catalog_enabled','loan_drafts_enabled',
            'external_borrowed_intake_enabled','external_borrowed_return_enabled',
            'external_borrowed_discrepancy_enabled',
            'external_borrowed_discrepancy_clearance_enabled',
            'external_in_loss_obligation_enabled','external_in_loss_settlement_enabled',
            'external_in_nonclean_closure_enabled'
        ] as $name)config()->set('inventory_controls.'.$name,true);
        config()->set('finance_controls.posting_enabled',true);
        $company=Company::create(['name'=>'ENCL Reversal Race Company',
            'slug'=>'encl-reversal-race-company']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'ENCL Reversal Race Hall',
            'slug'=>'encl-reversal-race-hall','capacity'=>100]);
        $people=[];
        foreach([['drafter','manager'],['handler','manager'],['owner','owner'],
            ['reviewer','owner'],['approver','owner']] as [$name,$role]) {
            $actor=User::create(['name'=>'ENCL reversal '.$name,
                'email'=>'encl-reverse-'.$name.'@example.test','password'=>'test-only']);
            $actor->markEmailAsVerified();
            $membership=CompanyMembership::create(['company_id'=>$company->id,
                'user_id'=>$actor->id,'role'=>$role]);
            if($role==='manager')HallAssignment::create([
                'company_id'=>$company->id,'hall_id'=>$hall->id,'membership_id'=>$membership->id]);
            $people[$name]=$actor;
        }
        ['drafter'=>$drafter,'handler'=>$handler,'owner'=>$owner,
            'reviewer'=>$reviewer,'approver'=>$approver]=$people;
        $item=$this->id();
        $this->be($drafter);
        app(HallInventoryCatalogService::class)->create($drafter,$company->id,$hall->id,
            $item,'One settled borrowed unit','External partner','piece',1,
            'Original ENCL reversal race certification');
        $loan=$this->readyOriginal($company,$hall,$item,3,
            $drafter,$handler,$owner,$reviewer,$approver);
        $settlement=(string)DB::table('hall_inventory_external_in_loss_settlements')
            ->where('company_id',$company->id)->where('loan_id',$loan)->value('id');
        $this->assertTrue(Str::isUuid($settlement));
        $this->assertSame(1,DB::table('finance_journals')->where('company_id',$company->id)->count());
        $closureId=$this->id();$reversalId=$this->id();
        $this->be($reviewer);
        $this->assertTrue(function_exists('pcntl_fork'));
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
        $this->assertIsArray($pair);
        [$parentSocket,$childSocket]=$pair;
        $resultFile=tempnam(sys_get_temp_dir(),'encl-reversal-lock-');
        $this->assertNotFalse($resultFile);
        // No inherited live PostgreSQL socket may be closed by the forked child.
        DB::purge();
        $pid=pcntl_fork();
        $this->assertNotSame(-1,$pid);
        if($pid===0) {
            fclose($parentSocket);
            DB::purge();
            try {
                $backendPid=(int)DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                fwrite($childSocket,"ready:".$backendPid."\n");
                fgets($childSocket);
                app(HallInventoryExternalInLossSettlementService::class)->reverse(
                    $reviewer,$company->id,$hall->id,$settlement,$reversalId,
                    'Post-final simulated disputed payment requiring independent reversal');
                file_put_contents($resultFile,json_encode(['unexpected'=>'reversal accepted'],JSON_THROW_ON_ERROR));
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
        $closed=null;
        DB::beginTransaction();
        try {
            $this->assertNotNull(DB::table('companies')->where('id',$company->id)
                ->lockForUpdate()->first(['id']));
            stream_set_timeout($parentSocket,15);
            $ready=(string)fgets($parentSocket);
            $this->assertMatchesRegularExpression('/^ready:[0-9]+$/',trim($ready));
            $backendPid=(int)substr(trim($ready),6);
            fwrite($parentSocket,"go\n");
            $wait=false;
            for($i=0;$i<100;$i++) {
                if(DB::table('pg_stat_activity')->where('pid',$backendPid)
                    ->value('wait_event_type')==='Lock'){$wait=true;break;}
                usleep(100000);
            }
            $this->assertTrue($wait,'Payment reversal did not wait for original loan issuance lock.');
            $this->be($approver);
            $closed=app(HallInventoryExternalInNoncleanClosureService::class)
                ->close($approver,$company->id,$hall->id,$loan,$closureId);
            DB::commit();
        }catch(\Throwable $e) {
            DB::rollBack();$parentError=$e;
        }finally {
            fclose($parentSocket);
            pcntl_waitpid($pid,$status);
        }
        if($parentError!==null)throw $parentError;
        $this->assertTrue(pcntl_wifexited($status));
        $child=json_decode((string)file_get_contents($resultFile),true,512,JSON_THROW_ON_ERROR);
        unlink($resultFile);
        $this->assertArrayHasKey('rejected',$child,json_encode($child));
        $this->assertStringContainsString('immutable final non-clean closure',$child['rejected']);
        $this->assertSame('ENCL-1',$closed['serialNumber']);
        $this->assertSame(1,DB::table('hall_inventory_external_in_nonclean_closures')
            ->where('company_id',$company->id)->where('loan_id',$loan)->count());
        $this->assertSame(0,DB::table('hall_inventory_external_in_loss_settlement_reversals')
            ->where('company_id',$company->id)->count());
        $this->assertSame(1,DB::table('finance_journals')->where('company_id',$company->id)->count());
        $this->assertSame(1,DB::table('hall_inventory_movement_events')
            ->where('company_id',$company->id)->where('item_id',$item)
            ->where('event_kind','writeoff_borrowed_transit')->count());
        $this->assertSame(0,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$hall->id)
            ->where('item_id',$item)->value('borrowed_transit'));
        $this->assertSame(1,DB::table('audit_events')
            ->where('company_id',$company->id)
            ->where('action','hall_inventory.external_in_nonclean_closed')->count());
        $this->assertSame(0,DB::table('audit_events')
            ->where('company_id',$company->id)
            ->where('action','hall_inventory.external_in_loss_settlement_reversed')->count());
        $this->assertSame($closed,app(HallInventoryExternalInNoncleanClosureService::class)
            ->close($approver,$company->id,$hall->id,$loan,$closureId));
        $stored=DB::table('hall_inventory_external_in_nonclean_closures')->where('id',$closureId)->first();
        $bytes=Storage::disk('local')->get((string)$stored->pdf_path);
        $this->assertSame((int)$stored->pdf_size,strlen($bytes));
        $this->assertSame((string)$stored->pdf_sha256,hash('sha256',$bytes));
    }

}
