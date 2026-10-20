<?php

namespace Tests\Feature;

use App\Filament\Pages\HallInventory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryPositionReport;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class HallInventoryPositionReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_owned_and_borrowed_book_balances_and_low_stock_warning_without_any_writes():void
    {
        $company=Company::create(['name'=>'Book Report Co','slug'=>'book-report-co']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Book Hall','slug'=>'book-hall','capacity'=>100]);
        $other=Hall::create(['company_id'=>$company->id,'name'=>'Other Book Hall','slug'=>'other-book-hall','capacity'=>100]);
        $manager=User::create(['name'=>'Book Report Manager','email'=>'book-report-manager@example.test','password'=>'test-only']);
        $manager->markEmailAsVerified();
        $membership=CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$manager->id,'role'=>'manager']);
        HallAssignment::create(['company_id'=>$company->id,'hall_id'=>$hall->id,'membership_id'=>$membership->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($manager);
        config()->set('inventory_controls.catalog_enabled',true);
        $catalog=app(HallInventoryCatalogService::class);
        $item=(string)Str::uuid();$unposted=(string)Str::uuid();
        $catalog->create($manager,$company->id,$hall->id,$item,
            'Book report chairs','Furniture','piece',15,'Approved hall stock report');
        $catalog->create($manager,$company->id,$hall->id,$unposted,
            'Catalog only linen','Linen','piece',12,'Not yet purchased');
        $unsafe=(string)Str::uuid();
        $catalog->create($manager,$company->id,$hall->id,$unsafe,
            '=SUM(1+1)','Supplies','piece',0,'Legacy imported catalog name');
        $inconsistent=(string)Str::uuid();
        $catalog->create($manager,$company->id,$hall->id,$inconsistent,
            'Suspect movement snapshot','Supplies','piece',3,'Reconciliation test fixture');
        $position=(string)Str::uuid();
        DB::table('hall_inventory_positions')->insert([
            'id'=>$position,'company_id'=>$company->id,'hall_id'=>$hall->id,
            'item_id'=>$item,'owned_usable'=>10,'owned_on_loan'=>3,
            'owned_damaged'=>2,'borrowed_usable'=>4,'revision'=>1,
            'created_at'=>now(),'updated_at'=>now()
        ]);
        // Privileged legacy corruption simulation: nonzero revision without
        // matching immutable movement MUST NOT masquerade as verified book stock.
        DB::table('hall_inventory_positions')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,
            'hall_id'=>$hall->id,'item_id'=>$inconsistent,
            'owned_usable'=>99,'revision'=>3,'created_at'=>now(),'updated_at'=>now()
        ]);
        // Latest revision alone is insufficient: 2 earlier events are absent.
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,'hall_id'=>$hall->id,
            'item_id'=>$inconsistent,
            'position_id'=>(string)DB::table('hall_inventory_positions')
                ->where('item_id',$inconsistent)->value('id'),
            'event_kind'=>'acquire_owned','quantity'=>99,
            'revision_before'=>2,'revision_after'=>3,
            'request_fingerprint'=>hash('sha256','incomplete ledger fixture'),
            'reason'=>'Synthetic incomplete journal chain',
            'before_values'=>json_encode(['ownedUsable'=>0],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode(['ownedUsable'=>99],JSON_THROW_ON_ERROR),
            'actor_id'=>$manager->id,
        ]);
        $zeros=['ownedUsable'=>0,'ownedOnLoan'=>0,'ownedDamaged'=>0,
            'borrowedUsable'=>0,'borrowedTransit'=>0,'borrowedDamaged'=>0];
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,'hall_id'=>$hall->id,
            'item_id'=>$item,'position_id'=>$position,
            'event_kind'=>'acquire_owned','quantity'=>10,'revision_before'=>0,
            'revision_after'=>1,'request_fingerprint'=>hash('sha256','book report fixture'),
            'reason'=>'Fixture prior approved movement',
            'before_values'=>json_encode($zeros,JSON_THROW_ON_ERROR),
            'after_values'=>json_encode(array_merge($zeros,['ownedUsable'=>10]),JSON_THROW_ON_ERROR),
            'actor_id'=>$manager->id,
        ]);
        $service=app(HallInventoryPositionReport::class);
        try {
            $service->recent($manager,$company->id,$hall->id);
            $this->fail('Default OFF reporting gate allowed data.');
        }catch(ConflictHttpException){}
        config()->set('inventory_controls.position_reports_enabled',true);
        $rows=$service->recent($manager,$company->id,$hall->id);
        $this->assertCount(4,$rows);
        $csv=$service->csv($manager,$company->id,$hall->id);
        $this->assertStringContainsString('Owned usable', $csv);
        $this->assertStringContainsString('Book report chairs', $csv);
        $this->assertStringContainsString('BELOW_MINIMUM', $csv);
        $this->assertStringContainsString("'=SUM(1+1)",$csv);
        $this->assertStringNotContainsString('finance_journals',$csv);
        Livewire::test(HallInventory::class)
            ->call('downloadPositionReportCsv')
            ->assertFileDownloaded('hall-inventory-book-'.substr($hall->id,0,8).'.csv');
        $this->assertSame(1,DB::table('audit_events')
            ->where('action','hall_inventory.book_report_csv_exported')
            ->where('company_id',$company->id)
            ->where('entity_id',$hall->id)->count());
        $books=collect($rows)->firstWhere('itemId',$item);
        $this->assertSame(10,$books['ownedUsable']);
        $this->assertSame(3,$books['ownedOnLoan']);
        $this->assertSame(4,$books['borrowedUsable']);
        $this->assertSame(2,$books['ownedDamaged']);
        $this->assertSame('BELOW_MINIMUM',$books['status']);
        $this->assertTrue($books['needsReplenishment']);
        $suspect=collect($rows)->firstWhere('itemId',$inconsistent);
        $this->assertSame('RECONCILIATION_REQUIRED',$suspect['status']);
        $this->assertNull($suspect['ownedUsable']);
        $this->assertFalse($suspect['needsReplenishment']);
        $empty=collect($rows)->firstWhere('itemId',$unposted);
        $this->assertSame('NO_POSTED_MOVEMENT',$empty['status']);
        $this->assertNull($empty['ownedUsable']);
        $this->assertFalse($empty['needsReplenishment']);
        Livewire::test(HallInventory::class)
            ->assertSee('Hall stock balances and minimum-stock warnings')
            ->assertSee('LOW OWNED STOCK / REPLENISHMENT REVIEW')
            ->assertSee('Catalog only linen')
            ->assertSee('No posted movement');
        $this->assertSame(2,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        try{$service->recent($manager,$company->id,$other->id);
            $this->fail('Unassigned hall accessed.');
        }catch(AuthorizationException){}
        DB::table('company_memberships')->where('id',$membership->id)
            ->update(['is_active'=>false]);
        try{$service->recent($manager,$company->id,$hall->id);
            $this->fail('Revoked role retained report access.');
        }catch(AuthorizationException){}
        Livewire::test(HallInventory::class)->assertForbidden();
    }
}
