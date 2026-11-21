<?php
namespace Tests\Feature;

use App\Filament\Pages\CustomerDirectory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_decrypted_customer_and_manager_is_denied():void
    {
        $company=Company::create(['name'=>'Directory Venue','slug'=>'directory-venue']);
        $owner=User::create(['name'=>'Directory Owner','email'=>'directory-owner@example.test','password'=>'test-only']);
        $manager=User::create(['name'=>'Directory Manager','email'=>'directory-manager@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$manager,'manager']] as [$user,$role]){
            $user->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$user->id,'role'=>$role]);
        }
        $customerId=(string) \Illuminate\Support\Str::uuid();
        DB::table('customer_profiles')->insert([
            'id'=>$customerId,'company_id'=>$company->id,
            'name_ciphertext'=>Crypt::encryptString('Ayesha Khan'),
            'email_ciphertext'=>Crypt::encryptString('ayesha@example.test'),
            'phone_ciphertext'=>Crypt::encryptString('03001234567'),
            'created_by'=>$owner->id,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        Livewire::test(CustomerDirectory::class)
            ->assertSee('Customer directory')
            ->assertSee('Ayesha Khan')
            ->assertSee('ayesha@example.test')
            ->call('openCustomer',$customerId)
            ->assertSee('Reservation history')
            ->set('editName','Ayesha Khan Updated')
            ->set('editReason','Correct spelling after customer confirmation')
            ->call('saveCustomer')
            ->assertHasNoErrors();
        $saved=DB::table('customer_profiles')->where('id',$customerId)->first();
        $this->assertSame('Ayesha Khan Updated',Crypt::decryptString($saved->name_ciphertext));
        $this->assertSame(2,(int)$saved->revision);
        $this->be($manager);
        Livewire::test(CustomerDirectory::class)->assertForbidden();
    }
}
