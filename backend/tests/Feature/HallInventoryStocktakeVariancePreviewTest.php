<?php

namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryStocktakeService;
use App\Services\HallInventoryStocktakeVariancePreview;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class HallInventoryStocktakeVariancePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_immutable_count_time_snapshot_drives_physical_variance_after_later_stock_moves():void
    {
        $company=Company::create(['name'=>'Snapshot Variance Co','slug'=>'snapshot-variance-co']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Snapshot Hall','slug'=>'snapshot-hall','capacity'=>100]);
        $other=Hall::create(['company_id'=>$company->id,'name'=>'Other Snapshot Hall','slug'=>'other-snapshot-hall','capacity'=>100]);
        $manager=User::create(['name'=>'Variance Manager','email'=>'variance-manager@example.test','password'=>'test-only']);
        $manager->markEmailAsVerified();
        $membership=CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$manager->id,'role'=>'manager']);
        HallAssignment::create(['company_id'=>$company->id,
            'membership_id'=>$membership->id,'hall_id'=>$hall->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($manager);
        foreach(['catalog_enabled','stocktakes_enabled','position_reports_enabled'] as $flag)
            config()->set('inventory_controls.'.$flag,true);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create(
            $manager,$company->id,$hall->id,$item,
            'Snapshot glassware','Glassware','piece',10,'Hall glassware audit');
        $pos=(string)Str::uuid();
        DB::table('hall_inventory_positions')->insert([
            'id'=>$pos,'company_id'=>$company->id,'hall_id'=>$hall->id,
            'item_id'=>$item,'owned_usable'=>10,'revision'=>1,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $before=['ownedUsable'=>0,'ownedOnLoan'=>0,'ownedDamaged'=>0,
            'borrowedUsable'=>0,'borrowedTransit'=>0,'borrowedDamaged'=>0];
        $after=array_merge($before,['ownedUsable'=>10]);
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,
            'hall_id'=>$hall->id,'item_id'=>$item,'position_id'=>$pos,
            'event_kind'=>'acquire_owned','quantity'=>10,
            'revision_before'=>0,'revision_after'=>1,
            'request_fingerprint'=>hash('sha256','stocktake first book'),
            'reason'=>'Previous independently accepted movement',
            'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
            'actor_id'=>$manager->id
        ]);
        $stocktake=(string)Str::uuid();
        app(HallInventoryStocktakeService::class)->submit($manager,
            $company->id,$hall->id,$stocktake,'monthly',[
                ['itemId'=>$item,'ownership'=>'owned','usableCount'=>8,'damagedCount'=>1],
            ],'Monthly count independently recorded');
        $snapshot=DB::table('hall_inventory_stocktake_book_snapshots')->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('POSTED_BOOK_SNAPSHOT',$snapshot->book_state);
        $this->assertSame(10,(int)$snapshot->book_usable);
        $this->assertSame(0,(int)$snapshot->book_damaged);
        $this->assertSame(1,(int)$snapshot->book_revision);

        // A later legitimate movement changes CURRENT book, never the
        // historical book captured atomically with the original count.
        DB::table('hall_inventory_positions')->where('id',$pos)->where('revision',1)
            ->update(['owned_usable'=>90,'revision'=>2,'updated_at'=>now()]);
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,
            'hall_id'=>$hall->id,'item_id'=>$item,'position_id'=>$pos,
            'event_kind'=>'acquire_owned','quantity'=>80,
            'revision_before'=>1,'revision_after'=>2,
            'request_fingerprint'=>hash('sha256','stocktake later book'),
            'reason'=>'Another independently accepted stock receipt',
            'before_values'=>json_encode($after,JSON_THROW_ON_ERROR),
            'after_values'=>json_encode(array_merge($after,['ownedUsable'=>90]),JSON_THROW_ON_ERROR),
            'actor_id'=>$manager->id
        ]);
        $review=app(HallInventoryStocktakeVariancePreview::class);
        try {$review->forStocktake($manager,$company->id,$hall->id,$stocktake);
            $this->fail('Default OFF variance allowed.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.stocktake_variance_enabled',true);
        $rows=$review->forStocktake($manager,$company->id,$hall->id,$stocktake);
        $this->assertCount(1,$rows);
        $this->assertSame('POSTED_BOOK_SNAPSHOT',$rows[0]['status']);
        $this->assertSame(10,$rows[0]['bookUsable']);
        $this->assertSame(8,$rows[0]['countedUsable']);
        $this->assertSame(-2,$rows[0]['usableVariance']);
        $this->assertSame(1,$rows[0]['damagedVariance']);
        $this->assertTrue($rows[0]['requiresReview']);
        $this->assertFalse($rows[0]['adjustmentPosted']);
        Livewire::test(HallInventory::class)
            ->call('viewStocktakeVariance',$stocktake)->assertHasNoErrors()
            ->assertSee('Physical versus recorded book snapshot')
            ->assertSee('Snapshot glassware')->assertSee('REVIEW REQUIRED');
        try {$review->forStocktake($manager,$company->id,$other->id,$stocktake);
            $this->fail('Unassigned or wrong-hall variance allowed.');
        }catch(AuthorizationException){}
        // An item never opened/posted has no numeric book baseline. Its
        // physical count may be saved but must never yield invented variance.
        $unposted=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create(
            $manager,$company->id,$hall->id,$unposted,
            'No baseline linen','Linen','piece',5,'Inventory unposted fixture');
        $secondTake=(string)Str::uuid();
        app(HallInventoryStocktakeService::class)->submit(
            $manager,$company->id,$hall->id,$secondTake,'quarterly',[
                ['itemId'=>$unposted,'ownership'=>'owned','usableCount'=>5,'damagedCount'=>0],
            ],'Quarterly count without posted stock');
        $second=$review->forStocktake($manager,$company->id,$hall->id,$secondTake);
        $this->assertCount(1,$second);
        $this->assertSame('NO_POSTED_BOOK',$second[0]['status']);
        $this->assertNull($second[0]['bookUsable']);
        $this->assertNull($second[0]['usableVariance']);
        $this->assertTrue($second[0]['requiresReview']);
        $this->assertSame(0,DB::table('finance_journals')->count());

        // Review attestation is independent from count submission, immutable,
        // and explicitly must not post stock, an expense or a write-off.
        $owner=User::create(['name'=>'Independent Variance Reviewer',
            'email'=>'variance-review-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        $reviewService=app(\App\Services\HallInventoryVarianceReviewService::class);
        $ackId=(string)Str::uuid();
        try {
            $reviewService->acknowledge($manager,$company->id,$hall->id,
                $stocktake,$ackId,'Physical discrepancy needs investigation');
            $this->fail('Count author self-reviewed the count.');
        } catch(AuthorizationException) {}
        $this->be($owner);
        $this->assertSame($ackId,$reviewService->acknowledge(
            $owner,$company->id,$hall->id,$stocktake,$ackId,
            'Physical discrepancy needs investigation'));
        $this->assertSame($ackId,$reviewService->acknowledge(
            $owner,$company->id,$hall->id,$stocktake,$ackId,
            'Physical discrepancy needs investigation'));
        try {
            $reviewService->acknowledge($owner,$company->id,$hall->id,
                $stocktake,$ackId,'Different findings require new review');
            $this->fail('Conflicting variance attestation replayed.');
        } catch(ConflictHttpException) {}
        $this->assertSame(1,DB::table('hall_inventory_variance_review_acknowledgments')->count());
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.stocktake_variance_review_acknowledged')->count());
        Livewire::test(HallInventory::class)
            ->call('viewStocktakeVariance',$secondTake)->assertHasNoErrors()
            ->set('varianceReviewReason','Separate physical count variance reviewed')
            ->call('acknowledgeStocktakeVariance')->assertHasNoErrors()
            ->assertSee('An independent reviewer acknowledged this physical variance.');
        $this->assertSame(2,DB::table('hall_inventory_variance_review_acknowledgments')->count());
        $history=app(HallInventoryStocktakeVariancePreview::class)
            ->forStocktake($owner,$company->id,$hall->id,$secondTake);
        $this->assertTrue($history[0]['reviewAcknowledged']);
        $this->assertNotNull($history[0]['reviewAcknowledgedAt']);
        Livewire::test(HallInventory::class)
            ->call('viewStocktakeVariance',$secondTake)->assertHasNoErrors()
            ->assertSee('An independent reviewer acknowledged this physical variance.')
            ->assertDontSee('Record independent variance review');
        $this->assertSame(0,DB::table('finance_journals')->count());
        try {
            DB::transaction(function()use($ackId):void {
                DB::table('hall_inventory_variance_review_acknowledgments')
                    ->where('id',$ackId)->update(['review_reason'=>'tampered']);
            });
            $this->fail('Immutable independent variance review altered.');
        } catch(QueryException) {}
        $this->be($manager);
        try {
            DB::transaction(function()use($snapshot):void {
                DB::table('hall_inventory_stocktake_book_snapshots')
                    ->where('id',$snapshot->id)->update(['book_usable'=>900]);
            });
            $this->fail('Immutable count-time book snapshot altered.');
        }catch(QueryException){}

        DB::table('company_memberships')->where('id',$membership->id)
            ->update(['is_active'=>false]);
        try {$review->forStocktake($manager,$company->id,$hall->id,$stocktake);
            $this->fail('Revoked manager retained variance access.');
        }catch(AuthorizationException){}
    }
}
