<?php
namespace Tests\Feature;
use App\Filament\Pages\HallInventory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryLoanDraftService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class HallInventoryInternalSourceDispatchLivewireTest extends TestCase {
    use RefreshDatabase;
    public function test_source_owner_independently_dispatches_multiline_loan_and_manager_cannot():void {
        Storage::fake('local');
        $company=Company::create(['name'=>'Dispatch GUI Company','slug'=>'dispatch-gui-company']);
        $source=Hall::create(['company_id'=>$company->id,'name'=>'A Source',
            'slug'=>'source-ui','capacity'=>100]);
        $dest=Hall::create(['company_id'=>$company->id,'name'=>'B Destination',
            'slug'=>'dest-ui','capacity'=>100]);
        $drafter=User::create(['name'=>'Loan Draft Manager','email'=>'loan-ui-manager@example.test','password'=>'test-only']);
        $owner=User::create(['name'=>'Independent Owner','email'=>'loan-ui-owner@example.test','password'=>'test-only']);
        foreach([$drafter,$owner] as $u)$u->markEmailAsVerified();
        $member=CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$drafter->id,'role'=>'manager']);
        HallAssignment::create(['company_id'=>$company->id,'membership_id'=>$member->id,
            'hall_id'=>$source->id]);
        $ownerMember=CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($drafter);
        foreach(['catalog_enabled','loan_drafts_enabled'] as $key)
            config()->set('inventory_controls.'.$key,true);
        $lines=[];$starting=[];
        foreach([['Crockery trays',20,5],['Dining chairs',40,12]] as [$name,$owned,$quantity]) {
            $id=(string)Str::uuid();
            app(HallInventoryCatalogService::class)->create($drafter,$company->id,
                $source->id,$id,$name,'Furniture','piece',2,'Dispatch GUI fixture');
            DB::table('hall_inventory_positions')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$company->id,'hall_id'=>$source->id,
                'item_id'=>$id,'owned_usable'=>$owned,'revision'=>0,
                'created_at'=>now(),'updated_at'=>now()
            ]);
            $lines[]=['itemId'=>$id,'quantity'=>$quantity];
            $starting[$id]=[$owned,$quantity];
        }
        $loan=(string)Str::uuid();
        app(HallInventoryLoanDraftService::class)->create($drafter,$company->id,$loan,
            'internal',$source->id,$dest->id,$lines,'2027-10-19',
            'Independent source-dispatch UI inventory');
        $this->assertFalse(config('inventory_controls.loan_dispatch_enabled'));
        Livewire::test(HallInventory::class)->assertDontSee('Prepare witnessed source dispatch');
        config()->set('inventory_controls.loan_dispatch_enabled',true);
        Livewire::test(HallInventory::class)->assertDontSee('Prepare witnessed source dispatch')
            ->call('startInternalDispatch',$loan)->assertForbidden();
        $this->be($owner);
        $file=fn()=>UploadedFile::fake()->createWithContent('handover.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        Livewire::test(HallInventory::class)
            ->assertSet('hallId',$source->id)
            ->assertSee('Prepare witnessed source dispatch')
            ->call('startInternalDispatch',$loan)->assertHasNoErrors()
            ->assertSee('Independently witnessed source dispatch')
            ->set('loanDispatchFile',$file())
            ->call('submitInternalDispatch')->assertHasNoErrors()
            ->assertSee('SOURCE DISPATCHED / DESTINATION RECEIPT PENDING');
        foreach($starting as $id=>[$owned,$qty]) {
            $p=DB::table('hall_inventory_positions')->where('company_id',$company->id)
                ->where('hall_id',$source->id)->where('item_id',$id)->first();
            $this->assertSame($owned-$qty,(int)$p->owned_usable);
            $this->assertSame($qty,(int)$p->owned_on_loan);
            $this->assertSame(0,(int)$p->borrowed_usable);
            $this->assertSame(1,(int)$p->revision);
        }
        $this->assertSame(1,DB::table('hall_inventory_loan_dispatches')->count());
        $this->assertSame(2,DB::table('hall_inventory_loan_dispatch_lines')->count());
        $this->assertSame(0,DB::table('hall_inventory_internal_loan_receipts')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        Livewire::test(HallInventory::class)
            ->assertDontSee('Prepare witnessed source dispatch')
            ->call('startInternalDispatch',$loan)->assertForbidden();
        DB::table('company_memberships')->where('id',$ownerMember->id)->update(['is_active'=>false]);
        Livewire::test(HallInventory::class)->call('startInternalDispatch',$loan)->assertForbidden();
        $this->assertSame(1,DB::table('hall_inventory_loan_dispatches')->count());
    }
}
