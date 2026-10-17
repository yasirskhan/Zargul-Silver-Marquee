<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\VenueInvoiceService;
use App\Filament\Pages\VenueReservations;
use Livewire\Livewire;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class VenueInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Invoice Trial Venue', 'slug' => 'invoice-trial']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Invoice Hall',
            'slug' => 'invoice-test-hall', 'capacity' => 200]);
        $owner = User::create(['name' => 'Owner', 'email' => 'invoice-owner@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $manager = User::create(['name' => 'Manager', 'email' => 'invoice-manager@example.test',
            'password' => 'test-only']);
        $manager->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $manager->id, 'role' => 'manager']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $customer = (string) Str::uuid();
        DB::table('customer_profiles')->insert([
            'id' => $customer, 'company_id' => $company->id,
            'name_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString('Synthetic Invoice Client'),
            'created_by' => $owner->id,
        ]);
        $draft = (string) Str::uuid();
        DB::table('booking_drafts')->insert([
            'id' => $draft, 'company_id' => $company->id,
            'hall_id' => $hall->id, 'customer_id' => $customer,
            'event_date' => '2027-09-01', 'program' => 'evening',
            'requested_guests' => 100, 'status' => 'booked',
            'revision' => 3, 'created_by' => $owner->id,
        ]);
        $claim = (string) Str::uuid();
        DB::table('hall_slot_claims')->insert([
            'id' => $claim, 'company_id' => $company->id, 'hall_id' => $hall->id,
            'event_date' => '2027-09-01', 'program' => 'evening',
            'status' => 'confirmed', 'created_by' => $owner->id,
        ]);
        $reservation = (string) Str::uuid();
        DB::table('venue_reservations')->insert([
            'id' => $reservation, 'company_id' => $company->id,
            'source_draft_id' => $draft, 'customer_id' => $customer,
            'hall_id' => $hall->id, 'claim_id' => $claim,
            'event_date' => '2027-09-01', 'program' => 'evening',
            'guests' => 100, 'status' => 'confirmed',
            'revision' => 1, 'created_by' => $owner->id,
        ]);
        $snapshot = (string) Str::uuid();
        $frozen = json_encode([['code' => 'hall_rental', 'quantity' => 1,
            'rateMinor' => 150000, 'amountMinor' => 150000]], JSON_THROW_ON_ERROR);
        DB::table('venue_quote_snapshots')->insert([
            'id' => $snapshot, 'company_id' => $company->id,
            'reservation_id' => $reservation, 'reservation_revision' => 1,
            'hall_id' => $hall->id, 'program_revision' => 1,
            'seating_revision' => 1, 'addon_revision' => 0,
            'currency_code' => 'PKR', 'frozen_lines' => $frozen,
            'request_fingerprint' => str_repeat('b', 64),
            'subtotal_minor' => 150000, 'created_by' => $owner->id,
            'reason' => 'Synthetic legal pricing snapshot test',
        ]);
        $quote = (string) Str::uuid();
        DB::table('venue_quote_issues')->insert([
            'id' => $quote, 'company_id' => $company->id,
            'snapshot_id' => $snapshot, 'reservation_id' => $reservation,
            'number' => 1, 'document_number' => 'Q-000000001',
            'currency_code' => 'PKR', 'subtotal_minor' => 150000,
            'frozen_lines' => $frozen, 'purpose' => 'Synthetic quotation',
            'issued_by' => $owner->id, 'issued_at' => now(),
        ]);
        return [$company, $owner, $manager, $reservation, $quote];
    }

    public function test_tax_rate_and_operator_release_are_mandatory_for_numbered_invoice(): void
    {
        [$company, $owner, , $reservation, $quote] = $this->fixture();
        $service = app(VenueInvoiceService::class);
        $id = (string) Str::uuid();
        try {
            $service->issue($owner, $company->id, $quote, $id, 'Synthetic invoice test');
            $this->fail('Invoice gate bypassed.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::table('venue_invoices')->count());
        }
        config()->set('invoice_controls.issuance_enabled', true);
        try {
            $service->issue($owner, $company->id, $quote, $id, 'Synthetic invoice test');
            $this->fail('Unconfigured tax treatment was accepted.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::table('venue_invoices')->count());
        }
        config()->set('invoice_controls.tax_rate_bps', 1000);
        $issued = $service->issue($owner, $company->id, $quote, $id,
            'Synthetic invoice after approved tax config');
        $this->assertSame('INV-000000001', $issued['invoiceNumber']);
        $this->assertSame(150000, $issued['subtotalMinor']);
        $this->assertSame(15000, $issued['taxMinor']);
        $this->assertSame(165000, $issued['totalMinor']);
        $this->assertSame($issued, $service->issue($owner, $company->id, $quote, $id,
            'Synthetic invoice after approved tax config'));
        $this->assertSame([$id], array_column($service->list(
            $owner, $company->id, $reservation), 'id'));
        $this->assertSame(1, DB::table('venue_invoices')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'venue_invoice.issued')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_owner_follows_confirmed_booking_quote_into_gated_invoice_panel(): void
    {
        [$company, $owner, $manager, $reservation, $quote] = $this->fixture();
        $panel = Livewire::test(VenueReservations::class)
            ->call('selectReservation', $reservation)
            ->assertSee('Internal quote Q-000000001')
            ->assertDontSee('Issue numbered invoice from this quote');
        $panel->call('issueInvoice', $quote)->assertForbidden();
        $this->assertSame(0, DB::table('venue_invoices')->count());

        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 1000);
        Livewire::test(VenueReservations::class)
            ->call('selectReservation', $reservation)
            ->assertSee('Issue numbered invoice from this quote')
            ->set('invoiceIssueReason', 'Synthetic private issued invoice from quote')
            ->call('issueInvoice', $quote)->assertHasNoErrors()
            ->assertSee('INV-000000001')
            ->assertSee('165000')
            ->assertDontSee('invoice-owner@example.test');
        $this->assertSame(1, DB::table('venue_invoices')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('finance_journal_lines')->count());
        $this->be($manager);
        Livewire::test(VenueReservations::class)
            ->call('selectReservation', $reservation)->assertDontSee('Invoice INV-000000001');
    }

    public function test_manager_denied_and_stale_reservation_prevents_invoice(): void
    {
        [$company, $owner, $manager, $reservation, $quote] = $this->fixture();
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 0);
        $this->be($manager);
        try {
            app(VenueInvoiceService::class)->issue($manager, $company->id, $quote,
                (string) Str::uuid(), 'Manager may not issue invoice');
            $this->fail('Manager issued private invoice.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($owner);
        DB::table('venue_reservations')->where('id', $reservation)->update(['revision' => 2]);
        $this->expectException(ConflictHttpException::class);
        app(VenueInvoiceService::class)->issue($owner, $company->id, $quote,
            (string) Str::uuid(), 'Reject stale rescheduled booking');
    }

    public function test_issued_invoice_is_immutable_and_preserves_historical_price(): void
    {
        [$company, $owner, , , $quote] = $this->fixture();
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 0);
        $id = (string) Str::uuid();
        app(VenueInvoiceService::class)->issue($owner, $company->id, $quote, $id,
            'Freeze an immutable legal trial invoice');
        $this->expectException(QueryException::class);
        DB::table('venue_invoices')->where('id', $id)->update(['total_minor' => 0]);
    }
}
