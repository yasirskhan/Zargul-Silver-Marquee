<?php
namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HallInventoryVarianceCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_variance_export_is_scoped_audited_and_spreadsheet_safe():void
    {
        $company=Company::create(['name'=>'Variance CSV Co','slug'=>'variance-csv-co']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'CSV Hall','slug'=>'csv-hall','capacity'=>50]);
        $manager=User::create(['name'=>'CSV Manager','email'=>'csv-manager@example.test','password'=>'test-only']);
        $manager->markEmailAsVerified();
        $membership=CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$manager->id,'role'=>'manager']);
        HallAssignment::create(['company_id'=>$company->id,'membership_id'=>$membership->id,'hall_id'=>$hall->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($manager);
        foreach(['catalog_enabled','stocktakes_enabled','position_reports_enabled','stocktake_variance_enabled'] as $flag)
            config()->set('inventory_controls.'.$flag,true);
        $stocktake=(string) \Illuminate\Support\Str::uuid();
        $component=Livewire::test(HallInventory::class);
        $page=$component->instance();
        $page->varianceStocktakeId=$stocktake;
        $page->stocktakeVariance=[[ 
                'itemId'=>(string) \Illuminate\Support\Str::uuid(),'name'=>'=SUM(A1:A2)','unit'=>'piece',
                'ownership'=>'owned','countedUsable'=>8,'countedDamaged'=>1,'bookUsable'=>10,
                'bookDamaged'=>0,'usableVariance'=>-2,'damagedVariance'=>1,
                'status'=>'POSTED_BOOK_SNAPSHOT','requiresReview'=>true,'adjustmentPosted'=>false,
            ]];
        ob_start();
        $component->instance()->downloadStocktakeVarianceCsv()->sendContent();
        $csv=(string) ob_get_clean();
        $this->assertStringContainsString('item_id,item,unit,ownership', $csv);
        $this->assertStringContainsString("'=SUM(A1:A2)", $csv);
        $this->assertStringContainsString('yes,no', $csv);
        $this->assertSame(1,DB::table('audit_events')->where('action','hall_inventory.stocktake_variance_csv_exported')
            ->where('company_id',$company->id)->where('entity_id',$stocktake)->count());
    }
}
