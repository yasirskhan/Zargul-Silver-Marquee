<?php

namespace Tests\Feature;

use App\Filament\Pages\HistoricalInvoices;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationIdentity;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class HistoricalInvoicesPageTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email): User
    {
        $user=User::create(['name'=>$email,'email'=>$email,'password'=>'test-only']);
        $user->markEmailAsVerified();
        return $user;
    }

    public function test_unlicensed_enrolled_owner_can_read_empty_historic_page_but_manager_cannot(): void
    {
        config()->set('central_control.activation_enabled', true);
        $company=Company::create(['name'=>'History Company','slug'=>'history-company']);
        $foreign=Company::create(['name'=>'Other Company','slug'=>'other-company']);
        $owner=$this->owner('historical-owner@example.test');
        $manager=$this->owner('historical-manager@example.test');
        $foreignOwner=$this->owner('historical-foreign@example.test');
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$manager->id,'role'=>'manager']);
        CompanyMembership::create([
            'company_id'=>$foreign->id,'user_id'=>$foreignOwner->id,'role'=>'owner']);
        $installation=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$installation['installation_id'],
            'company_id'=>$company->id,'customer_id'=>(string)Str::uuid(),
            'approved_domain'=>'history-company.example.test',
            'status'=>'registered_pending_license',
            'central_registered_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $view=Livewire::test(HistoricalInvoices::class)
            ->assertSet('companyId',$company->id)
            ->assertSet('invoices',[])
            ->assertSee('Past invoices and payment history')
            ->assertSee('No existing invoices found')
            ->assertDontSee('Other Company');
        $view->set('selectedInvoiceId',(string)Str::uuid())
            ->call('viewStatement')->assertForbidden();
        Livewire::test(HistoricalInvoices::class,['companyId'=>$foreign->id])
            ->assertForbidden();
        $this->be($manager);
        $this->assertFalse(HistoricalInvoices::canAccess());
        Livewire::test(HistoricalInvoices::class)->assertForbidden();
        $this->be($foreignOwner);
        $this->assertFalse(HistoricalInvoices::canAccess());
        Livewire::test(HistoricalInvoices::class)->assertForbidden();
        $this->assertDatabaseCount('central_signed_license_cache',0);
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_owner_role_revocation_blocks_existing_historic_session_and_no_payment_is_created(): void
    {
        $company=Company::create(['name'=>'Role Change Company','slug'=>'history-revoke']);
        $owner=$this->owner('historical-revoke@example.test');
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $view=Livewire::test(HistoricalInvoices::class)
            ->assertSet('companyId',$company->id)
            ->assertSet('loadedCompanyId',$company->id)
            ->assertSet('statement',null);
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$owner->id)->update(['role'=>'manager']);
        $view->call('refreshInvoices')->assertForbidden();
        $this->assertDatabaseCount('venue_invoice_payments',0);
        $this->assertDatabaseCount('venue_invoice_refunds',0);
        $this->assertDatabaseCount('finance_journals',0);
    }
}
