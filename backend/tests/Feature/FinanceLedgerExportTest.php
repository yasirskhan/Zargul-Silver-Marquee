<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Filament\Pages\FinanceDesk;
use App\Services\FinanceControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceLedgerExportTest extends TestCase
{
    use RefreshDatabase;


    public function test_csv_export_contains_all_lines_and_excludes_other_companies(): void
    {
        $company=Company::create(['name'=>'CSV Venue','slug'=>'csv-venue']);
        $other=Company::create(['name'=>'CSV Other','slug'=>'csv-other']);
        $owner=User::create(['name'=>'CSV Owner','email'=>'csv-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $this->be($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach([[$company,'PKR',100], [$other,'USD',999]] as [$target,$currency,$amount]){
            $journal=(string) \Illuminate\Support\Str::uuid();
            DB::table('finance_journals')->insert(['id'=>$journal,'company_id'=>$target->id,
                'entry_type'=>'opening_cash','currency_code'=>$currency,'amount_minor'=>$amount,
                'reason'=>'CSV export fixture','created_by'=>$owner->id,'created_at'=>now()]);
            foreach([['cash_on_hand','debit'],['opening_equity','credit']] as [$account,$side]){
                DB::table('finance_journal_lines')->insert(['id'=>(string) \Illuminate\Support\Str::uuid(),
                    'company_id'=>$target->id,'journal_id'=>$journal,'account_code'=>$account,
                    'side'=>$side,'amount_minor'=>$amount,'created_at'=>now()]);
            }
        }
        $component=Livewire::test(FinanceDesk::class);
        ob_start();
        $component->instance()->exportJournalsCsv()->sendContent();
        $csv=(string) ob_get_clean();
        $this->assertStringContainsString('journal_id,created_at,entry_type', $csv);
        $this->assertStringContainsString('cash_on_hand', $csv);
        $this->assertStringContainsString('opening_equity', $csv);
        $this->assertStringNotContainsString('USD', $csv);
        $this->assertStringNotContainsString('999', $csv);
    }


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
        if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
        $baseMemory=memory_get_usage(true);
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
        $this->assertLessThan(64*1024*1024,memory_get_peak_usage(true)-$baseMemory);
    }
}
