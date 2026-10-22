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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** ADR-0009 Option A: authorized write-off and numbered certificate, no company journal. */
class HallInventoryInternalAbnormalClosureTest extends TestCase {
    use DatabaseMigrations;
    protected function tearDown():void {
        try { DB::statement('TRUNCATE TABLE companies, users RESTART IDENTITY CASCADE'); }
        finally { parent::tearDown(); }
    }
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
            'item_id'=>$item,'owned_usable'=>2,'revision'=>0,'created_at'=>now(),'updated_at'=>now()
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
        // A different live original loan uses the same company/hall/catalog
        // position. Retiring this loan must not retire the second loan's unit.
        $secondLoan=(string)Str::uuid();
        $this->be($owner);
        app(HallInventoryLoanDraftService::class)->create($owner,$company->id,$secondLoan,'internal',
            $source->id,$borrower->id,[['itemId'=>$item,'quantity'=>1]],'2027-12-30',
            'Independent overlapping original loan fixture');
        $this->be($admin);
        app(HallInventoryInternalLoanDispatchService::class)->dispatch($admin,$company->id,$secondLoan,
            (string)Str::uuid(),[$item=>1],$this->pdf());
        $this->be($manager);
        app(HallInventoryInternalLoanReceiptService::class)->receive($manager,$company->id,$secondLoan,
            (string)Str::uuid(),[$item=>$mapped],[$mapped=>1],$this->pdf());
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_on_loan'));
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
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_on_loan'));
        $closureId=(string)Str::uuid();
        $certificatePath='private/hall-internal-abnormal/'.$company->id.'/'.$loan.'/'.$closureId.'.pdf';
        // Two live, independently committed PostgreSQL connections: holding
        // the company serial allocator must block issuance *before* write-off.
        config(['database.connections.iac_second_pgsql'=>config('database.connections.pgsql')]);
        DB::purge('iac_second_pgsql');
        $other=DB::connection('iac_second_pgsql');
        $this->assertNotSame(DB::connection()->getPdo(),$other->getPdo());
        $other->beginTransaction();
        try {
            $this->assertNotNull($other->table('companies')->where('id',$company->id)
                ->lockForUpdate()->first(['id']));
            DB::statement("SET lock_timeout = '300ms'");
            try {
                $service->close($authorizer,$company->id,$source->id,$loan,$closureId);
                $this->fail('IAC issuance bypassed independent company numbering lock.');
            }catch(QueryException $e) {
                $this->assertStringContainsString('lock timeout',strtolower($e->getMessage()));
            }
            $this->assertSame(0,$other->table('hall_inventory_internal_abnormal_closures')
                ->where('company_id',$company->id)->count());
            $this->assertSame(2,(int)$other->table('hall_inventory_positions')
                ->where('company_id',$company->id)->where('hall_id',$source->id)
                ->where('item_id',$item)->value('owned_on_loan'));
            $this->assertFalse(Storage::disk('local')->exists($certificatePath));
        }finally{
            DB::statement('SET lock_timeout TO DEFAULT');
            $other->rollBack();
            DB::disconnect('iac_second_pgsql');
        }
        // Signed borrower, independent source and reviewed settlement bytes
        // must still match their immutable hashes at final authorization.
        foreach([
            ['hall_inventory_internal_physical_findings','source_proof_path'],
            ['hall_inventory_internal_discrepancy_statements','evidence_storage_path'],
            ['hall_inventory_internal_loss_settlements','proof_path']
        ] as [$table,$pathColumn]) {
            $row=DB::table($table)->where('company_id',$company->id)->first();
            $path=(string)$row->{$pathColumn};
            $original=Storage::disk('local')->get($path);
            Storage::disk('local')->put($path,'corrupted signed evidence');
            try {
                $service->close($authorizer,$company->id,$source->id,$loan,$closureId);
                $this->fail('Irreversible closeout ignored corrupted original evidence.');
            }catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException){}
            $this->assertSame(0,DB::table('hall_inventory_internal_abnormal_closures')->count());
            $this->assertSame(2,(int)DB::table('hall_inventory_positions')
                ->where('company_id',$company->id)->where('hall_id',$source->id)
                ->where('item_id',$item)->value('owned_on_loan'));
            Storage::disk('local')->put($path,$original);
        }
        // Force a real PostgreSQL failure *after* PDF storage, then check
        // rolled-back positions, cleared orphan and same-ID recovery.
        DB::unprepared("CREATE FUNCTION phase12_iac_injected_failure() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'phase12 injected certificate insert failure'; END;
            $$ LANGUAGE plpgsql");
        DB::statement('CREATE TRIGGER phase12_iac_injected_failure BEFORE INSERT ON
            hall_inventory_internal_abnormal_closures FOR EACH ROW
            EXECUTE FUNCTION phase12_iac_injected_failure()');
        $auditBefore=DB::table('audit_events')->where('company_id',$company->id)->count();
        try {
            try {
                $service->close($authorizer,$company->id,$source->id,$loan,$closureId);
                $this->fail('PostgreSQL injected certificate transaction failure was ignored.');
            }catch(QueryException $e) {
                $this->assertStringContainsString('phase12 injected certificate insert failure',$e->getMessage());
            }
        }finally {
            DB::statement('DROP TRIGGER phase12_iac_injected_failure ON hall_inventory_internal_abnormal_closures');
            DB::statement('DROP FUNCTION phase12_iac_injected_failure()');
        }
        $this->assertSame($auditBefore,DB::table('audit_events')
            ->where('company_id',$company->id)->count());
        $this->assertSame(0,DB::table('hall_inventory_internal_abnormal_closures')->count());
        $this->assertFalse(Storage::disk('local')->exists($certificatePath));
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_on_loan'));
        // Occupied immutable storage key refuses issuance, leaves positions
        // untouched and allows an exact retry after the obstruction is removed.
        Storage::disk('local')->put($certificatePath,'occupied by failed issuance');
        try {
            $service->close($authorizer,$company->id,$source->id,$loan,$closureId);
            $this->fail('Occupied certificate key was overwritten.');
        }catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException){}
        $this->assertSame(0,DB::table('hall_inventory_internal_abnormal_closures')->count());
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')
            ->where('company_id',$company->id)->where('hall_id',$source->id)
            ->where('item_id',$item)->value('owned_on_loan'));
        $this->assertSame('occupied by failed issuance',Storage::disk('local')->get($certificatePath));
        Storage::disk('local')->delete($certificatePath);
        // A real, separately connected PostgreSQL issuer must wait for the
        // original company's lock, then replay the committed original close
        // without issuing another serial, stock movement or audit event.
        $this->assertTrue(function_exists('pcntl_fork'),'IAC process certification requires pcntl_fork');
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
        $this->assertIsArray($pair);
        [$parentSocket,$childSocket]=$pair;
        $resultFile=tempnam(sys_get_temp_dir(),'iac-two-process-');
        $this->assertNotFalse($resultFile);
        $beforeMovement=DB::table('hall_inventory_movement_events')
            ->where('event_kind','writeoff_owned_on_loan')->count();
        $beforeClosures=DB::table('hall_inventory_internal_abnormal_closures')->count();
        $beforeAudit=DB::table('audit_events')->where('company_id',$company->id)
            ->where('action','hall_inventory.internal_abnormal_closed')->count();
        // Close the *last* parent database connection immediately before fork.
        // Otherwise the child inherits the socket opened for these snapshots;
        // its DB::purge() can drop the parent's live PostgreSQL session.
        DB::purge();
        $pid=pcntl_fork();
        $this->assertNotSame(-1,$pid);
        if($pid===0) {
            fclose($parentSocket);
            DB::purge();
            $backendPid=(int)DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            fwrite($childSocket,"ready:".$backendPid."\n");
            fgets($childSocket);
            try {
                $result=$service->close($authorizer,$company->id,$source->id,$loan,$closureId);
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
            $readyLine=(string)fgets($parentSocket);
            $this->assertMatchesRegularExpression('/^ready:[0-9]+$/',trim($readyLine));
            $childBackendPid=(int)trim(substr($readyLine,6));
            fwrite($parentSocket,"go\n");
            $sawWait=false;
            for($poll=0;$poll<100;$poll++) {
                if(DB::table('pg_stat_activity')->where('pid',$childBackendPid)
                    ->value('wait_event_type')==='Lock') {$sawWait=true;break;}
                usleep(100000);
            }
            $this->assertTrue($sawWait,'Second IAC process did not wait on the original company lock.');
            $closed=$service->close($authorizer,$company->id,$source->id,$loan,$closureId);
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
        $this->assertArrayNotHasKey('error',$child,json_encode($child));
        $this->assertSame($closed,$child['result']);
        $this->assertSame($beforeClosures+1,DB::table('hall_inventory_internal_abnormal_closures')
            ->where('company_id',$company->id)->count());
        $this->assertSame($beforeMovement+1,DB::table('hall_inventory_movement_events')
            ->where('company_id',$company->id)->where('event_kind','writeoff_owned_on_loan')->count());
        $this->assertSame($beforeAudit+1,DB::table('audit_events')->where('company_id',$company->id)
            ->where('action','hall_inventory.internal_abnormal_closed')->count());
        $this->assertSame('IAC-1',$closed['serialNumber']);
        $this->assertSame(1,$closed['writeoffQuantity']);
        $this->assertSame(1000,$closed['amountMinor']);
        $this->assertSame(1,(int)DB::table('hall_inventory_positions')
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
        // Missing/corrupt final proof must never be silently reissued or
        // served as valid immutable evidence.
        Storage::disk('local')->put($certificatePath,'corrupted');
        try {
            $service->close($authorizer,$company->id,$source->id,$loan,$closureId);
            $this->fail('Corrupted certificate was treated as an idempotent success.');
        }catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException){}
        try {
            $service->download($authorizer,$company->id,$source->id,$closureId);
            $this->fail('Corrupted certificate downloaded.');
        }catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException){}
        Storage::disk('local')->delete($certificatePath);
        try {
            $service->close($authorizer,$company->id,$source->id,$loan,$closureId);
            $this->fail('Missing certificate was silently reminted.');
        }catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException){}
        Storage::disk('local')->put($certificatePath,$pdf);
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
        $before=DB::table('audit_events')->where('company_id',$company->id)
            ->where('action','hall_inventory.internal_abnormal_certificate_downloaded')->count();
        $download=$service->download($authorizer,$company->id,$source->id,$closureId);
        $this->assertSame($pdf,$download['bytes']);
        $readiness=app(\App\Services\HallInventoryInternalAbnormalReadinessService::class)
            ->assess($authorizer,$company->id,$source->id,$loan);
        $this->assertTrue($readiness['ready']);
        $this->assertSame(1,$readiness['originalPhysicalUnitsBeforeWriteoff']);
        $this->assertSame(0,$readiness['physicalUnitsOpen']);
        $this->assertSame([],$readiness['blockers']);
        // Already issued certificates must not appear safely reconciled if
        // their only stored original is subsequently corrupted.
        Storage::disk('local')->put($certificatePath,'tampered final artifact');
        $invalid=app(\App\Services\HallInventoryInternalAbnormalReadinessService::class)
            ->assess($authorizer,$company->id,$source->id,$loan);
        $this->assertFalse($invalid['ready']);
        $this->assertContains('Final numbered certificate is missing or failed immutable hash verification.',
            $invalid['blockers']);
        Storage::disk('local')->put($certificatePath,$pdf);
        $this->assertSame($before+1,DB::table('audit_events')->where('company_id',$company->id)
            ->where('action','hall_inventory.internal_abnormal_certificate_downloaded')->count());
        // A formerly authorized signer retains their immutable issued record,
        // but immediately loses all read rights after membership revocation.
        CompanyMembership::query()->where('company_id',$company->id)
            ->where('user_id',$authorizer->id)->update(['is_active'=>false]);
        try {
            $service->download($authorizer,$company->id,$source->id,$closureId);
            $this->fail('Revoked owner membership downloaded the private final certificate.');
        }catch(AuthorizationException){}
        $this->assertSame($before+1,DB::table('audit_events')->where('company_id',$company->id)
            ->where('action','hall_inventory.internal_abnormal_certificate_downloaded')->count());
    }
}
