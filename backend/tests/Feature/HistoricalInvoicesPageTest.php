<?php

namespace Tests\Feature;

use App\Filament\Pages\HistoricalInvoices;
use App\Models\Company;
use App\Models\Hall;
use App\Services\TenantAccess;
use App\Services\VenueInvoiceService;
use Illuminate\Support\Facades\Crypt;
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
            ->assertSet('offset',0)
            ->assertSet('hasMore',false)
            ->call('nextPage')->assertSet('offset',0)
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


    public function test_signed_license_restriction_preserves_read_only_real_issued_invoice_statement(): void
    {
        // Create one genuine immutable invoice using existing booking/quote
        // services BEFORE operator commercial enforcement is turned on.
        $company=Company::create(['name'=>'Historic Invoice Venue','slug'=>'historic-invoice-venue']);
        $hall=Hall::create(['company_id'=>$company->id,
            'name'=>'Historic Invoice Hall','slug'=>'historic-invoice-hall','capacity'=>200]);
        $owner=$this->owner('historic-issued-owner@example.test');
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $customer=(string)Str::uuid();
        DB::table('customer_profiles')->insert([
            'id'=>$customer,'company_id'=>$company->id,
            'name_ciphertext'=>Crypt::encryptString('Private Historic Client'),
            'created_by'=>$owner->id,
        ]);
        $draft=(string)Str::uuid();
        DB::table('booking_drafts')->insert([
            'id'=>$draft,'company_id'=>$company->id,
            'hall_id'=>$hall->id,'customer_id'=>$customer,
            'event_date'=>'2027-09-01','program'=>'evening','requested_guests'=>100,
            'status'=>'booked','revision'=>3,'created_by'=>$owner->id,
        ]);
        $claim=(string)Str::uuid();
        DB::table('hall_slot_claims')->insert([
            'id'=>$claim,'company_id'=>$company->id,'hall_id'=>$hall->id,
            'event_date'=>'2027-09-01','program'=>'evening',
            'status'=>'confirmed','created_by'=>$owner->id,
        ]);
        $reservation=(string)Str::uuid();
        DB::table('venue_reservations')->insert([
            'id'=>$reservation,'company_id'=>$company->id,
            'source_draft_id'=>$draft,'customer_id'=>$customer,
            'hall_id'=>$hall->id,'claim_id'=>$claim,
            'event_date'=>'2027-09-01','program'=>'evening',
            'guests'=>100,'status'=>'confirmed',
            'revision'=>1,'created_by'=>$owner->id,
        ]);
        $frozen=json_encode([['code'=>'hall_rental','quantity'=>1,
            'rateMinor'=>150000,'amountMinor'=>150000]], JSON_THROW_ON_ERROR);
        $snapshot=(string)Str::uuid();
        DB::table('venue_quote_snapshots')->insert([
            'id'=>$snapshot,'company_id'=>$company->id,
            'reservation_id'=>$reservation,'reservation_revision'=>1,
            'hall_id'=>$hall->id,'program_revision'=>1,
            'seating_revision'=>1,'addon_revision'=>0,
            'currency_code'=>'PKR','frozen_lines'=>$frozen,
            'request_fingerprint'=>str_repeat('b',64),
            'subtotal_minor'=>150000,'created_by'=>$owner->id,
            'reason'=>'Synthetic historic legal price snapshot',
        ]);
        $quote=(string)Str::uuid();
        DB::table('venue_quote_issues')->insert([
            'id'=>$quote,'company_id'=>$company->id,
            'snapshot_id'=>$snapshot,'reservation_id'=>$reservation,
            'number'=>1,'document_number'=>'Q-000000001','currency_code'=>'PKR',
            'subtotal_minor'=>150000,'frozen_lines'=>$frozen,
            'purpose'=>'Historic quote','issued_by'=>$owner->id,'issued_at'=>now(),
        ]);
        config()->set('invoice_controls.issuance_enabled',true);
        config()->set('invoice_controls.tax_rate_bps',1000);
        $invoiceId=(string)Str::uuid();
        app(VenueInvoiceService::class)->issue(
            $owner,$company->id,$quote,$invoiceId,'Historic pre-expiry invoice issuance');
        $this->assertDatabaseCount('venue_invoices',1);

        // The original installed company remains bound, but there is no
        // signed paid license. New bookings and payments are restricted.
        config()->set('central_control.activation_enabled',true);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'company_id'=>$company->id,'customer_id'=>(string)Str::uuid(),
            'approved_domain'=>'historic-invoice.example.test',
            'status'=>'registered_pending_license',
            'central_registered_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->assertFalse(app(TenantAccess::class)->canManageBookings($owner,$hall));
        Livewire::test(HistoricalInvoices::class)
            ->assertSee('INV-000000001')
            ->assertDontSee('Private Historic Client')
            ->assertSet('invoices.0.id',$invoiceId)
            ->assertSet('offset',0)
            ->assertSet('hasMore',false)
            ->set('selectedInvoiceId',$invoiceId)
            ->call('viewStatement')
            ->assertSet('statement.invoiceId',$invoiceId)
            ->assertSet('statement.paidMinor',0)
            ->assertSee('1,650.00')
            ->assertSee('No recorded payments.');
        $this->assertDatabaseCount('venue_invoice_payments',0);
        $this->assertDatabaseCount('venue_invoice_refunds',0);
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
