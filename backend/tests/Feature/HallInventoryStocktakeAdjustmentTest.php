<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryStocktakeAdjustmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryStocktakeAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_actor_reclassification_posts_once_and_conserves_stock(): void
    {
        $company=Company::create(['name'=>'Adjustment Firm','slug'=>'adjustment-firm']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Adjustment Hall','slug'=>'adjustment-hall','capacity'=>100]);
        $owner=User::create(['name'=>'Adjustment Owner','email'=>'adjustment-owner@example.test','password'=>'test-only']);
        $admin=User::create(['name'=>'Adjustment Admin','email'=>'adjustment-admin@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$admin,'admin']] as [$user,$role]) {
            $user->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$user->id,'role'=>$role]);
        }
        config()->set('inventory_controls.catalog_enabled',true);
        config()->set('inventory_controls.stocktakes_enabled',true);
        config()->set('inventory_controls.stocktake_variance_enabled',true);
        config()->set('inventory_controls.position_reports_enabled',true);
        config()->set('inventory_controls.stocktake_adjustment_enabled',true);
        $this->be($owner);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($owner,$company->id,$hall->id,$item,
            'Adjustment Chair','Furniture','piece',1,'Adjustment stock item');
        DB::table('hall_inventory_positions')->insert(['id'=>(string)Str::uuid(),
            'company_id'=>$company->id,'hall_id'=>$hall->id,'item_id'=>$item,
            'owned_usable'=>9,'owned_damaged'=>0,'revision'=>1,
            'created_at'=>now(),'updated_at'=>now()]);
        $stocktake=(string)Str::uuid();
        DB::table('hall_inventory_stocktakes')->insert(['id'=>$stocktake,'company_id'=>$company->id,
            'hall_id'=>$hall->id,'audit_kind'=>'monthly','request_fingerprint'=>hash('sha256','adjustment-test'),
            'reason'=>'Adjustment acceptance stocktake','counted_by'=>$owner->id,'counted_at'=>now()]);
        DB::table('hall_inventory_stocktake_lines')->insert(['id'=>(string)Str::uuid(),
            'company_id'=>$company->id,'hall_id'=>$hall->id,'stocktake_id'=>$stocktake,
            'item_id'=>$item,'ownership'=>'owned','usable_count'=>8,'damaged_count'=>1,'note'=>'']);
        $adjustment=(string)Str::uuid();
        app(HallInventoryStocktakeAdjustmentService::class)->propose($owner,$company->id,$hall->id,
            $stocktake,$adjustment,'Reclassify one usable chair as damaged');
        try {
            app(HallInventoryStocktakeAdjustmentService::class)->approve($owner,$company->id,$hall->id,
                $adjustment,'Owner self approval is forbidden');
            $this->fail('Self approval was allowed.');
        } catch (AuthorizationException) {}
        $this->be($admin);
        $result=app(HallInventoryStocktakeAdjustmentService::class)->approve($admin,$company->id,$hall->id,
            $adjustment,'Independent damaged stock approval');
        $this->assertSame(8,$result['usableAfter']);
        $this->assertSame(1,$result['damagedAfter']);
        $position=DB::table('hall_inventory_positions')->where('item_id',$item)->first();
        $this->assertSame(8,(int)$position->owned_usable);
        $this->assertSame(1,(int)$position->owned_damaged);
        $this->assertSame(2,(int)$position->revision);
        $this->assertSame(1,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame('reclassify_owned_damage',
            DB::table('hall_inventory_movement_events')->value('event_kind'));
        $again=app(HallInventoryStocktakeAdjustmentService::class)->approve($admin,$company->id,$hall->id,
            $adjustment,'Independent damaged stock approval');
        $this->assertSame($result['movementId'],$again['movementId']);
        $this->assertSame(1,DB::table('hall_inventory_movement_events')->count());
        $this->be($owner);
        $this->expectException(ConflictHttpException::class);
        app(HallInventoryStocktakeAdjustmentService::class)->propose($owner,$company->id,$hall->id,
            $stocktake,(string)Str::uuid(),'Duplicate adjustment request for same line');
    }
}
