<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\FinanceControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceLedgerExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_streams_every_journal_with_lines_beyond_ten_thousand():void
    {
        $company=Company::create(['name'=>'Ledger Export Venue','slug'=>'ledger-export-venue']);
        $owner=User::create(['name'=>'Ledger Owner','email'=>'ledger-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $this->be($owner);
        $total=10005;
        for($offset=0;$offset<$total;$offset+=500){
            $journals=[];$lines=[];
            for($i=$offset;$i<min($total,$offset+500);$i++){
                $id=(string) \Illuminate\Support\Str::uuid();
                $journals[]=['id'=>$id,'company_id'=>$company->id,'entry_type'=>'opening_cash',
                    'currency_code'=>'PKR','amount_minor'=>100,'reason'=>'Batch export fixture',
                    'created_by'=>$owner->id,'created_at'=>now()->subSeconds($total-$i)];
                $lines[]=['id'=>(string) \Illuminate\Support\Str::uuid(),'company_id'=>$company->id,
                    'journal_id'=>$id,'account_code'=>'cash_on_hand','side'=>'debit',
                    'amount_minor'=>100,'created_at'=>now()->subSeconds($total-$i)];
            }
            DB::table('finance_journals')->insert($journals);
            DB::table('finance_journal_lines')->insert($lines);
        }
        $batches=app(FinanceControlService::class)->journalExportBatches($owner,$company->id);
        $count=0;$withLines=0;$lastCreated=null;$lastId=null;$ordered=true;
        foreach($batches as $batch){
            $this->assertLessThanOrEqual(500,count($batch));
            foreach($batch as $row){
                $count++;
                $this->assertCount(1,$row['lines']);
                $withLines++;
                if($lastCreated!==null&&([$row['createdAt'],$row['id']]<[$lastCreated,$lastId]))$ordered=false;
                $lastCreated=$row['createdAt'];$lastId=$row['id'];
            }
        }
        $this->assertSame($total,$count);
        $this->assertSame($total,$withLines);
        $this->assertTrue($ordered);
        $this->assertSame($total,DB::table('finance_journals')->where('company_id',$company->id)->count());
    }
}
