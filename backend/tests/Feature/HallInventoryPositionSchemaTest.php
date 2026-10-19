<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class HallInventoryPositionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_position_schema_is_scoped_and_catalog_creation_never_books_stock():void
    {
        $this->assertTrue(Schema::hasTable('hall_inventory_positions'));
        $this->assertTrue(Schema::hasTable('hall_inventory_movement_events'));

        $company=Company::create([
            'name'=>'Position Evidence Business',
            'slug'=>'position-evidence-business',
        ]);
        $hall=Hall::create([
            'company_id'=>$company->id,
            'name'=>'Position Source Hall',
            'slug'=>'position-source-hall',
            'capacity'=>250,
        ]);
        $owner=User::create([
            'name'=>'Inventory Owner',
            'email'=>'position-owner@example.test',
            'password'=>'local-test',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id'=>$company->id,
            'user_id'=>$owner->id,
            'role'=>'owner',
        ]);
        $this->be($owner);
        $id=(string)Str::uuid();
        app(HallInventoryCatalogService::class)->create(
            $owner,$company->id,$hall->id,$id,
            'Dining plates','Catering','piece',12,
            'Inventory catalog only, no physical receipt'
        );
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());

        // No runtime posting API exists yet. This synthetic schema fixture
        // is only to verify the six buckets have zero book balance.
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),
            'company_id'=>$company->id,'hall_id'=>$hall->id,'item_id'=>$id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $row=DB::table('hall_inventory_positions')->first();
        foreach([
            'owned_usable','owned_on_loan','owned_damaged',
            'borrowed_usable','borrowed_transit','borrowed_damaged','revision'
        ] as $bucket){
            $this->assertSame(0,(int)$row->$bucket);
        }
        $this->assertSame(0,DB::table('finance_journals')->count());
    }

    public function test_movement_journal_has_immutable_trigger_and_tenant_composite_keys():void
    {
        $constraints=DB::table('pg_constraint as c')
            ->join('pg_class as t','t.oid','=','c.conrelid')
            ->whereIn('t.relname',[
                'hall_inventory_positions','hall_inventory_movement_events'
            ])->pluck('c.conname')->all();

        foreach([
            'hall_inventory_positions_counts_check',
            'hall_inventory_movement_kind_check',
            'hall_inventory_movement_qty_check',
        ] as $name){
            $this->assertContains($name,$constraints);
        }
        $trigger=DB::table('pg_trigger as tg')
            ->join('pg_class as t','t.oid','=','tg.tgrelid')
            ->where('t.relname','hall_inventory_movement_events')
            ->where('tg.tgname','immutable_hall_inventory_movement_events')
            ->where('tg.tgisinternal',false)->count();
        $this->assertSame(1,$trigger);

        // The movement journal remains empty until a future independently
        // authorized/verified receipt service writes exact audit evidence.
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
    }
}
