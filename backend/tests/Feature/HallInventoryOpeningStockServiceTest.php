<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryOpeningStockService;
use App\Services\HallInventoryStocktakeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallInventoryOpeningStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture():array
    {
        $company=Company::create(['name'=>'Opening Stock Business',
            'slug'=>'opening-stock-business']);
        $hall=Hall::create(['company_id'=>$company->id,
            'name'=>'Opening Hall','slug'=>'opening-hall','capacity'=>200]);
        $another=Hall::create(['company_id'=>$company->id,
            'name'=>'Other Opening Hall','slug'=>'other-opening-hall','capacity'=>150]);
        $owner=User::create(['name'=>'Opening Owner',
            'email'=>'opening-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        $admin=User::create(['name'=>'Opening Admin',
            'email'=>'opening-admin@example.test','password'=>'test-only']);
        $admin->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$admin->id,'role'=>'admin']);
        $manager=User::create(['name'=>'Opening Manager',
            'email'=>'opening-manager@example.test','password'=>'test-only']);
        $manager->markEmailAsVerified();
        $membership=CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$manager->id,'role'=>'manager']);
        HallAssignment::create(['company_id'=>$company->id,
            'hall_id'=>$hall->id,'membership_id'=>$membership->id]);
        $this->be($owner);
        $item=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create(
            $owner,$company->id,$hall->id,$item,
            'Hall tablecloths','Linen','piece',10,
            'Catalog new tablecloths'
        );
        return [$company,$hall,$another,$owner,$admin,$manager,$item];
    }

    private function openingCount(User $manager,string $companyId,
        string $hallId,string $itemId,int $qty):string
    {
        config()->set('inventory_controls.stocktakes_enabled',true);
        $this->be($manager);
        $take=(string)Str::uuid();
        app(HallInventoryStocktakeService::class)->submit(
            $manager,$companyId,$hallId,$take,'opening',[
                ['itemId'=>$itemId,'ownership'=>'owned',
                 'usableCount'=>$qty,'damagedCount'=>0],
            ],'Witnessed physical opening count'
        );
        return $take;
    }

    public function test_independent_opening_posts_one_immutable_audited_movement_after_real_physical_count():void
    {
        [$c,$hall,$other,$owner,$admin,$manager,$item]=$this->fixture();
        $service=app(HallInventoryOpeningStockService::class);
        $req=(string)Str::uuid();
        $this->expectException(ConflictHttpException::class);
        $service->request(
            $manager,$c->id,$hall->id,$item,(string)Str::uuid(),
            $req,50,'Physical stock opened'
        );
    }

    public function test_two_distinct_authorized_actors_reconcile_original_count_without_financial_posting():void
    {
        [$c,$hall,$other,$owner,$admin,$manager,$item]=$this->fixture();
        $take=$this->openingCount($manager,$c->id,$hall->id,$item,50);
        config()->set('inventory_controls.opening_stock_posting_enabled',true);
        $service=app(HallInventoryOpeningStockService::class);
        $request=(string)Str::uuid();
        $this->assertSame($request,$service->request(
            $manager,$c->id,$hall->id,$item,$take,
            $request,50,'Physical stock opened'
        ));
        $this->assertSame($request,$service->request(
            $manager,$c->id,$hall->id,$item,$take,
            $request,50,'Physical stock opened'
        ));
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());

        $this->be($owner);
        $movement=$service->accept($owner,$c->id,$hall->id,$request);
        $this->assertSame($movement,$service->accept($owner,$c->id,$hall->id,$request));
        $position=DB::table('hall_inventory_positions')->first();
        $this->assertSame(50,(int)$position->owned_usable);
        $this->assertSame(0,(int)$position->owned_on_loan);
        $this->assertSame(0,(int)$position->borrowed_usable);
        $this->assertSame(1,(int)$position->revision);
        $this->assertSame(1,DB::table('hall_inventory_positions')->count());
        $this->assertSame(1,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(1,DB::table('hall_inventory_opening_acceptances')->count());
        $event=DB::table('hall_inventory_movement_events')->first();
        $this->assertSame('opening_owned',$event->event_kind);
        $this->assertSame($item,$event->item_id);
        $this->assertSame(50,(int)$event->quantity);
        $this->assertSame((int)$owner->id,(int)$event->actor_id);
        $this->assertSame(0,DB::table('finance_journals')->count());

        $this->be($admin);
        try {
            $service->accept($admin,$c->id,$hall->id,$request);
            $this->fail('A different second approver altered a posted opening.');
        }catch(ConflictHttpException) {}
        $this->be($manager);
        try {
            $service->request($manager,$c->id,$hall->id,$item,$take,
                (string)Str::uuid(),50,'Other attempted opening');
            $this->fail('Second opening would inflate owned stock.');
        }catch(ConflictHttpException) {}
        $this->assertSame(50,(int)DB::table('hall_inventory_positions')
            ->value('owned_usable'));
        $this->assertSame(1,DB::table('hall_inventory_movement_events')->count());
    }

    public function test_wrong_count_non_assigned_hall_and_self_approval_are_denied():void
    {
        [$c,$hall,$other,$owner,$admin,$manager,$item]=$this->fixture();
        $take=$this->openingCount($manager,$c->id,$hall->id,$item,50);
        config()->set('inventory_controls.opening_stock_posting_enabled',true);
        $service=app(HallInventoryOpeningStockService::class);
        try {
            $service->request($manager,$c->id,$hall->id,$item,$take,
                (string)Str::uuid(),49,'Incorrect counted quantity');
            $this->fail('Unmatched physical count was accepted.');
        }catch(ConflictHttpException) {}
        try {
            $service->request($manager,$c->id,$other->id,$item,$take,
                (string)Str::uuid(),50,'Different hall inventory');
            $this->fail('Manager could open another hall.');
        }catch(AuthorizationException) {}
        $request=(string)Str::uuid();
        $service->request($manager,$c->id,$hall->id,$item,$take,
            $request,50,'Initial witnessed quantity');
        $this->be($manager);
        try {
            $service->accept($manager,$c->id,$hall->id,$request);
            $this->fail('Manager could self-approve the count.');
        }catch(AuthorizationException) {}
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
    }
}
