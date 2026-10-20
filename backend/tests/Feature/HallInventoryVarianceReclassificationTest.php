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
use App\Services\HallInventoryVarianceReviewService;
use App\Services\HallInventoryVarianceReclassificationService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class HallInventoryVarianceReclassificationTest extends TestCase {
    use RefreshDatabase;
    public function test_owned_condition_reclassification_posts_conserved_revision_only_after_independent_review():void {
        $co=Company::create(['name'=>'Variance Reclass Company','slug'=>'variance-reclass-co']);
        $hall=Hall::create(['company_id'=>$co->id,'name'=>'Reclass Hall','slug'=>'reclass-hall','capacity'=>100]);
        $other=Hall::create(['company_id'=>$co->id,'name'=>'Other Hall','slug'=>'reclass-other','capacity'=>100]);
        $counter=User::create(['name'=>'Physical Counter','email'=>'physical-counter@example.test','password'=>'test-only']);
        $counter->markEmailAsVerified();
        $member=CompanyMembership::create(['company_id'=>$co->id,'user_id'=>$counter->id,'role'=>'manager']);
        HallAssignment::create(['company_id'=>$co->id,'hall_id'=>$hall->id,'membership_id'=>$member->id]);
        $owner=User::create(['name'=>'Independent Owner','email'=>'independent-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$co->id,'user_id'=>$owner->id,'role'=>'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($counter);
        foreach(['catalog_enabled','stocktakes_enabled','position_reports_enabled','stocktake_variance_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create($counter,$co->id,$hall->id,$item,
            'Condition dishes','Crockery','piece',2,'Physical inspection fixture');
        $position=(string)Str::uuid();
        DB::table('hall_inventory_positions')->insert([
            'id'=>$position,'company_id'=>$co->id,'hall_id'=>$hall->id,
            'item_id'=>$item,'owned_usable'=>10,'revision'=>1,
            'created_at'=>now(),'updated_at'=>now()
        ]);
        $zero=['ownedUsable'=>0,'ownedOnLoan'=>0,'ownedDamaged'=>0,
            'borrowedUsable'=>0,'borrowedTransit'=>0,'borrowedDamaged'=>0];
        $book=array_replace($zero,['ownedUsable'=>10]);
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$co->id,'hall_id'=>$hall->id,
            'item_id'=>$item,'position_id'=>$position,'event_kind'=>'opening_owned',
            'quantity'=>10,'revision_before'=>0,'revision_after'=>1,
            'request_fingerprint'=>hash('sha256','initial owned count'),
            'reason'=>'Independently counted initial inventory',
            'before_values'=>json_encode($zero,JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($book,JSON_THROW_ON_ERROR),'actor_id'=>$counter->id
        ]);
        $take=(string)Str::uuid();
        app(HallInventoryStocktakeService::class)->submit($counter,$co->id,$hall->id,$take,'monthly',[
            ['itemId'=>$item,'ownership'=>'owned','usableCount'=>8,'damagedCount'=>2]
        ],'Count two owned items damaged');
        $line=DB::table('hall_inventory_stocktake_lines')->where('stocktake_id',$take)->first();
        $svc=app(HallInventoryVarianceReclassificationService::class);
        $reason='Inspected two damaged dishes, no items missing';
        try{$svc->reconcile($counter,$co->id,$hall->id,$take,$line->id,$reason);
            $this->fail('Default-off feature allowed stock movement.');}catch(ConflictHttpException){}
        config()->set('inventory_controls.variance_reclassification_enabled',true);
        try{$svc->reconcile($counter,$co->id,$hall->id,$take,$line->id,$reason);
            $this->fail('Counter independently approved self.');}catch(AuthorizationException){}
        $this->be($owner);
        try{$svc->reconcile($owner,$co->id,$hall->id,$take,$line->id,$reason);
            $this->fail('Review was not required.');}catch(AuthorizationException){}
        app(HallInventoryVarianceReviewService::class)->acknowledge($owner,$co->id,$hall->id,
            $take,(string)Str::uuid(),'Independent physical damage variance reviewed');
        try{$svc->reconcile($owner,$co->id,$other->id,$take,$line->id,$reason);
            $this->fail('Foreign-hall posting allowed.');}catch(ConflictHttpException|AuthorizationException){}
        Livewire::test(HallInventory::class)->set('hallId',$hall->id)
            ->call('viewStocktakeVariance',$take)->assertHasNoErrors()
            ->assertSee('Reclassify owned condition')
            ->set('varianceAdjudicationReason',$reason)
            ->call('reclassifyStocktakeDamage',$line->id)->assertHasNoErrors()
            ->assertSee('OWNED CONDITION RECLASSIFIED');
        $movement=DB::table('hall_inventory_movement_events')
            ->where('event_kind','reclassify_owned_damage')->first();
        $this->assertNotNull($movement);
        $this->assertSame(2,(int)$movement->quantity);
        $this->assertSame(8,(int)DB::table('hall_inventory_positions')->where('id',$position)->value('owned_usable'));
        $this->assertSame(2,(int)DB::table('hall_inventory_positions')->where('id',$position)->value('owned_damaged'));
        $this->assertSame((string)$movement->id,
            $svc->reconcile($owner,$co->id,$hall->id,$take,$line->id,$reason));
        try{$svc->reconcile($owner,$co->id,$hall->id,$take,$line->id,'Different reason for same decision');
            $this->fail('Conflicting replay allowed.');}catch(ConflictHttpException){}
        $this->assertSame(1,DB::table('hall_inventory_variance_reclassifications')->count());
        $this->assertSame(2,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        try {
            // PostgreSQL aborts a transaction after an expected trigger
            // rejection. Roll back only this SAVEPOINT so subsequent
            // assertions still exercise a usable test transaction.
            DB::transaction(function()use($line):void {
                DB::table('hall_inventory_variance_reclassifications')
                    ->where('stocktake_line_id',$line->id)->update(['quantity'=>20]);
            });
            $this->fail('Decision mutation allowed.');
        } catch(QueryException) {}
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.stocktake_condition_reclassified')->count());
        $this->be($counter);
        $missingTake=(string)Str::uuid();
        app(HallInventoryStocktakeService::class)->submit($counter,$co->id,$hall->id,
            $missingTake,'quarterly',[['itemId'=>$item,'ownership'=>'owned',
                'usableCount'=>7,'damagedCount'=>2]],'A physical item is missing');
        $missingLine=DB::table('hall_inventory_stocktake_lines')->where('stocktake_id',$missingTake)->first();
        $this->be($owner);
        app(HallInventoryVarianceReviewService::class)->acknowledge($owner,$co->id,$hall->id,
            $missingTake,(string)Str::uuid(),'Investigate one missing item separately');
        try{$svc->reconcile($owner,$co->id,$hall->id,$missingTake,$missingLine->id,$reason);
            $this->fail('Missing stock was secretly written off.');}catch(ConflictHttpException){}
        $this->assertSame(0,DB::table('finance_journals')->count());
    }
}
