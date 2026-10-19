<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryStocktakeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryStocktakeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Audit Venue', 'slug' => 'audit-inventory']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Marquee A',
            'slug' => 'marquee-a', 'capacity' => 300]);
        $otherHall = Hall::create(['company_id' => $company->id, 'name' => 'Marquee B',
            'slug' => 'marquee-b', 'capacity' => 200]);
        $manager = User::create(['name' => 'Manager', 'email' => 'stock-manager@example.test',
            'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create(['company_id' => $company->id,
            'membership_id' => $membership->id, 'hall_id' => $hall->id]);
        $this->be($manager);
        $cat = app(HallInventoryCatalogService::class);
        $spoons = (string) Str::uuid();
        $chairs = (string) Str::uuid();
        $cat->create($manager, $company->id, $hall->id, $spoons,
            'Spoons', 'Cutlery', 'piece', 180, 'Catalog for hall audit');
        $cat->create($manager, $company->id, $hall->id, $chairs,
            'Chairs', 'Furniture', 'piece', 500, 'Catalog for hall audit');
        return [$company, $hall, $otherHall, $manager, $membership, $spoons, $chairs];
    }

    public function test_manager_submits_multi_item_owned_and_borrowed_counts_without_stock_mutation(): void
    {
        [$company, $hall, , $manager, , $spoons, $chairs] = $this->fixture();
        $rows = [
            ['itemId'=>$chairs, 'ownership'=>'owned', 'usableCount'=>300, 'damagedCount'=>2],
            ['itemId'=>$spoons, 'ownership'=>'borrowed', 'usableCount'=>15, 'damagedCount'=>0],
            ['itemId'=>$spoons, 'ownership'=>'owned', 'usableCount'=>180, 'damagedCount'=>3],
        ];
        $id = (string) Str::uuid();
        $svc = app(HallInventoryStocktakeService::class);
        $this->assertSame($id, $svc->submit(
            $manager, $company->id, $hall->id, $id, 'monthly',
            $rows, 'Month end counted with witnessed quantities'));
        $this->assertSame($id, $svc->submit(
            $manager, $company->id, $hall->id, $id, 'monthly',
            array_reverse($rows), 'Month end counted with witnessed quantities'));
        $this->assertSame(1, DB::table('hall_inventory_stocktakes')->count());
        $this->assertSame(3, DB::table('hall_inventory_stocktake_lines')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'hall_inventory.physical_count_submitted')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_stocktake_rejects_unassigned_hall_and_catalog_id_from_another_hall(): void
    {
        [$company, $hall, $other, $manager, , $spoons] = $this->fixture();
        $svc = app(HallInventoryStocktakeService::class);
        $line = [['itemId'=>$spoons, 'ownership'=>'owned','usableCount'=>10,'damagedCount'=>0]];
        try {
            $svc->submit($manager, $company->id, $other->id,
                (string) Str::uuid(), 'post_event', $line, 'Invalid hall');
            $this->fail('Unassigned hall submitted count.');
        } catch (AuthorizationException) {}
        $this->assertSame(0, DB::table('hall_inventory_stocktakes')->count());
    }

    public function test_conflicting_fingerprint_is_rejected_without_overwriting_history(): void
    {
        [$company, $hall, , $manager, , $spoons] = $this->fixture();
        $svc = app(HallInventoryStocktakeService::class);
        $id = (string) Str::uuid();
        $one = [['itemId'=>$spoons, 'ownership'=>'owned','usableCount'=>100,'damagedCount'=>0]];
        $svc->submit($manager, $company->id, $hall->id, $id, 'opening', $one, 'Opening count verified');
        $two = [['itemId'=>$spoons, 'ownership'=>'owned','usableCount'=>90,'damagedCount'=>0]];
        $this->expectException(ConflictHttpException::class);
        $svc->submit($manager, $company->id, $hall->id, $id, 'opening', $two, 'Opening count verified');
    }

    public function test_invalid_and_duplicate_rows_never_create_stocktake(): void
    {
        [$company, $hall, , $manager, , $spoons] = $this->fixture();
        $svc = app(HallInventoryStocktakeService::class);
        $invalid = [['itemId'=>$spoons, 'ownership'=>'owned','usableCount'=>-1,'damagedCount'=>0]];
        try {
            $svc->submit($manager, $company->id, $hall->id, (string) Str::uuid(),
                'monthly', $invalid, 'Invalid negative count');
            $this->fail('Negative count was accepted.');
        } catch (\InvalidArgumentException) {}
        $valid = [['itemId'=>$spoons, 'ownership'=>'owned','usableCount'=>180,'damagedCount'=>0]];
        try {
            $svc->submit($manager, $company->id, $hall->id, (string) Str::uuid(),
                'monthly', [$valid[0], $valid[0]], 'Duplicate item row');
            $this->fail('Duplicate item was accepted.');
        } catch (\InvalidArgumentException) {}
        $this->assertSame(0, DB::table('hall_inventory_stocktakes')->count());
    }

    public function test_revoked_manager_cannot_submit_physical_counts(): void
    {
        [$company, $hall, , $manager, $membership, $spoons] = $this->fixture();
        DB::table('company_memberships')->where('id', $membership->id)
            ->update(['is_active'=>false]);
        $this->expectException(AuthorizationException::class);
        app(HallInventoryStocktakeService::class)->submit(
            $manager, $company->id, $hall->id, (string) Str::uuid(),
            'monthly', [['itemId'=>$spoons, 'ownership'=>'owned',
                'usableCount'=>100,'damagedCount'=>0]], 'Revoked manager cannot submit');
    }

    public function test_sql_cannot_delete_stocktake_count_evidence(): void
    {
        [$company, $hall, , $manager, , $spoons] = $this->fixture();
        $id = (string) Str::uuid();
        app(HallInventoryStocktakeService::class)->submit(
            $manager, $company->id, $hall->id, $id, 'quarterly',
            [['itemId'=>$spoons, 'ownership'=>'owned','usableCount'=>170,'damagedCount'=>5]],
            'Quarterly stocktake evidence');
        $this->expectException(QueryException::class);
        DB::table('hall_inventory_stocktake_lines')->where('stocktake_id', $id)->delete();
    }

    public function test_forged_item_from_another_company_or_invalid_count_is_rejected(): void
    {
        [$company, $hall, , $manager, , $spoons] = $this->fixture();
        $other = Company::create(['name'=>'External', 'slug'=>'external-count-company']);
        $otherHall = Hall::create(['company_id'=>$other->id,'name'=>'External Hall',
            'slug'=>'external-count-hall','capacity'=>200]);
        $this->expectException(AuthorizationException::class);
        app(HallInventoryStocktakeService::class)->submit(
            $manager, $other->id, $otherHall->id, (string) Str::uuid(), 'monthly',
            [['itemId'=>$spoons, 'ownership'=>'owned','usableCount'=>100,'damagedCount'=>0]],
            'Cross company should be denied');
    }
    public function test_history_reads_hall_counts_without_approving_stock_or_disclosing_other_halls():void
    {
        [$company,$hall,$other,$manager,$membership,$spoons,$chairs]=$this->fixture();
        config()->set('inventory_controls.stocktakes_enabled',true);
        $requestId=(string)Str::uuid();
        app(HallInventoryStocktakeService::class)->submit($manager,$company->id,
            $hall->id,$requestId,'monthly',[
                ['itemId'=>$spoons,'ownership'=>'owned','usableCount'=>180,'damagedCount'=>3],
                ['itemId'=>$spoons,'ownership'=>'borrowed','usableCount'=>15,'damagedCount'=>0],
                ['itemId'=>$chairs,'ownership'=>'owned','usableCount'=>300,'damagedCount'=>2],
            ],'Monthly physical count awaiting independent reconciliation');
        $review=app(\App\Services\HallInventoryStocktakeReview::class);
        $rows=$review->recent($manager,$company->id,$hall->id);
        $this->assertCount(1,$rows);
        $this->assertSame($requestId,$rows[0]['id']);
        $this->assertSame('monthly',$rows[0]['auditKind']);
        $this->assertTrue($rows[0]['evidenceOnly']);
        $this->assertFalse($rows[0]['adjustmentPosted']);
        $this->assertCount(3,$rows[0]['lines']);
        $damageCounts=array_column($rows[0]['lines'],'damaged');
        sort($damageCounts);
        $this->assertSame([0,2,3],$damageCounts);
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->expectException(AuthorizationException::class);
        $review->recent($manager,$company->id,$other->id);
    }

    public function test_history_read_gate_off_and_cross_company_are_denied():void
    {
        [$company,$hall,$other,$manager]=$this->fixture();
        $review=app(\App\Services\HallInventoryStocktakeReview::class);
        try{
            $review->recent($manager,$company->id,$hall->id);
            $this->fail('Unreleased history view accepted.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.stocktakes_enabled',true);
        $foreign=Company::create(['name'=>'Other Count Company',
            'slug'=>'other-count-review-business']);
        $foreignHall=Hall::create(['company_id'=>$foreign->id,
            'name'=>'Foreign Count Hall','slug'=>'foreign-count-review-hall','capacity'=>200]);
        $this->expectException(AuthorizationException::class);
        $review->recent($manager,$foreign->id,$foreignHall->id);
    }

    public function test_history_rejects_revoked_staff_and_unbounded_result_count():void
    {
        [$company,$hall,$other,$manager,$membership]=$this->fixture();
        config()->set('inventory_controls.stocktakes_enabled',true);
        $review=app(\App\Services\HallInventoryStocktakeReview::class);
        try{
            $review->recent($manager,$company->id,$hall->id,21);
            $this->fail('Unbounded stocktake history accepted.');
        }catch(\InvalidArgumentException){}
        DB::table('company_memberships')->where('id',$membership->id)
            ->update(['is_active'=>false]);
        $this->expectException(AuthorizationException::class);
        $review->recent($manager,$company->id,$hall->id);
    }

}
