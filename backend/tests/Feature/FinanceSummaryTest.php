<?php
namespace Tests\Feature;

use App\Filament\Pages\FinanceDesk;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private Hall $hall;
    private string $customer;
    private int $sequence = 0;
    private int $paymentSequence = 0;
    private int $refundSequence = 0;

    private function fixture(): void
    {
        $this->company = Company::create(['name' => 'Summary Venue', 'slug' => 'summary-venue']);
        $this->hall = Hall::create(['company_id' => $this->company->id, 'name' => 'Summary Hall',
            'slug' => 'summary-hall', 'capacity' => 100]);
        $this->owner = User::create(['name' => 'Summary Owner', 'email' => 'summary-owner@example.test',
            'password' => 'test-only']);
        $this->owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->be($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->customer = (string) Str::uuid();
        DB::table('customer_profiles')->insert([
            'id' => $this->customer, 'company_id' => $this->company->id,
            'name_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString('Summary Client'),
            'created_by' => $this->owner->id,
        ]);
    }

    private function invoice(string $currency, int $total, string $issuedAt): string
    {
        $this->sequence++;
        $draft = (string) Str::uuid();
        DB::table('booking_drafts')->insert([
            'id' => $draft, 'company_id' => $this->company->id, 'hall_id' => $this->hall->id,
            'customer_id' => $this->customer, 'event_date' => '2027-09-'.$this->sequence,
            'program' => 'evening', 'requested_guests' => 50, 'status' => 'booked',
            'revision' => 3, 'created_by' => $this->owner->id,
        ]);
        $claim = (string) Str::uuid();
        DB::table('hall_slot_claims')->insert([
            'id' => $claim, 'company_id' => $this->company->id, 'hall_id' => $this->hall->id,
            'event_date' => '2027-09-'.$this->sequence, 'program' => 'evening',
            'status' => 'confirmed', 'created_by' => $this->owner->id,
        ]);
        $reservation = (string) Str::uuid();
        DB::table('venue_reservations')->insert([
            'id' => $reservation, 'company_id' => $this->company->id,
            'source_draft_id' => $draft, 'customer_id' => $this->customer,
            'hall_id' => $this->hall->id, 'claim_id' => $claim,
            'event_date' => '2027-09-'.$this->sequence, 'program' => 'evening',
            'guests' => 50, 'status' => 'confirmed', 'revision' => 1,
            'created_by' => $this->owner->id,
        ]);
        $frozen = json_encode([['code' => 'hall_rental', 'quantity' => 1,
            'rateMinor' => $total, 'amountMinor' => $total]], JSON_THROW_ON_ERROR);
        $snapshot = (string) Str::uuid();
        DB::table('venue_quote_snapshots')->insert([
            'id' => $snapshot, 'company_id' => $this->company->id,
            'reservation_id' => $reservation, 'reservation_revision' => 1,
            'hall_id' => $this->hall->id, 'program_revision' => 1,
            'seating_revision' => 1, 'addon_revision' => 0,
            'currency_code' => $currency, 'frozen_lines' => $frozen,
            'request_fingerprint' => str_repeat('c', 64),
            'subtotal_minor' => $total, 'created_by' => $this->owner->id,
            'reason' => 'Summary fixture snapshot',
        ]);
        $quote = (string) Str::uuid();
        DB::table('venue_quote_issues')->insert([
            'id' => $quote, 'company_id' => $this->company->id,
            'snapshot_id' => $snapshot, 'reservation_id' => $reservation,
            'number' => $this->sequence, 'document_number' => 'Q-'.str_pad((string) $this->sequence, 9, '0', STR_PAD_LEFT),
            'currency_code' => $currency, 'subtotal_minor' => $total,
            'frozen_lines' => $frozen, 'purpose' => 'Summary quote',
            'issued_by' => $this->owner->id, 'issued_at' => $issuedAt,
        ]);
        $invoice = (string) Str::uuid();
        DB::table('venue_invoices')->insert([
            'id' => $invoice, 'company_id' => $this->company->id,
            'quote_issue_id' => $quote, 'reservation_id' => $reservation,
            'customer_id' => $this->customer, 'number' => $this->sequence,
            'invoice_number' => 'INV-'.str_pad((string) $this->sequence, 9, '0', STR_PAD_LEFT),
            'tax_rate_bps' => 0, 'currency_code' => $currency,
            'frozen_lines' => $frozen, 'subtotal_minor' => $total,
            'tax_minor' => 0, 'total_minor' => $total,
            'reason' => 'Summary fixture invoice', 'issued_by' => $this->owner->id,
            'issued_at' => $issuedAt,
        ]);
        return $invoice;
    }

    private function pay(string $invoice, string $currency, int $amount, string $createdAt): string
    {
        $journal = (string) Str::uuid();
        DB::table('finance_journals')->insert([
            'id' => $journal, 'company_id' => $this->company->id,
            'entry_type' => 'customer_deposit', 'currency_code' => $currency,
            'amount_minor' => $amount, 'reason' => 'Summary payment',
            'created_by' => $this->owner->id, 'created_at' => $createdAt,
        ]);
        $this->paymentSequence++;
        $id = (string) Str::uuid();
        DB::table('venue_invoice_payments')->insert([
            'id' => $id, 'company_id' => $this->company->id, 'invoice_id' => $invoice,
            'journal_id' => $journal, 'number' => $this->paymentSequence,
            'receipt_number' => 'RCP-'.str_pad((string) $this->paymentSequence, 9, '0', STR_PAD_LEFT),
            'method' => 'cash', 'currency_code' => $currency, 'amount_minor' => $amount,
            'reason' => 'Summary payment', 'created_by' => $this->owner->id,
            'created_at' => $createdAt,
        ]);
        return $id;
    }


    private function refund(string $invoice, string $payment, string $currency, int $amount, string $createdAt): void
    {
        $paymentRow = DB::table('venue_invoice_payments')->where('id', $payment)->first();
        $approval = (string) Str::uuid();
        DB::table('approval_requests')->insert([
            'id' => $approval, 'company_id' => $this->company->id,
            'booking_id' => DB::table('venue_reservations')->where('id', DB::table('venue_invoices')->where('id', $invoice)->value('reservation_id'))->value('source_draft_id'),
            'category' => 'refund', 'amount_minor' => $amount, 'currency' => $currency,
            'reason' => 'Summary refund approval', 'requested_by' => $this->owner->id,
            'status' => 'approved', 'revision' => 2,
        ]);
        $journal = (string) Str::uuid();
        DB::table('finance_journals')->insert([
            'id' => $journal, 'company_id' => $this->company->id,
            'entry_type' => 'reversal', 'currency_code' => $currency,
            'amount_minor' => $amount, 'reversal_of' => $paymentRow->journal_id,
            'reason' => 'Summary refund', 'created_by' => $this->owner->id,
            'created_at' => $createdAt,
        ]);
        $this->refundSequence++;
        DB::table('venue_invoice_refunds')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $this->company->id,
            'invoice_id' => $invoice, 'payment_id' => $payment,
            'approval_id' => $approval, 'journal_id' => $journal,
            'number' => $this->refundSequence,
            'refund_number' => 'REF-'.str_pad((string) $this->refundSequence, 9, '0', STR_PAD_LEFT),
            'currency_code' => $currency, 'amount_minor' => $amount,
            'reason' => 'Summary refund', 'recorded_by' => $this->owner->id,
            'created_at' => $createdAt,
        ]);
    }

    public function test_summary_includes_mixed_and_activity_only_currencies_at_utc_boundaries(): void
    {
        $this->fixture();
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 0);
        // USD invoice issued before period; payment for it lands inside the period.
        $olderUsd = $this->invoice('USD', 100000, '2026-01-15 12:00:00+00');
        // PKR invoice inside the period at the exact start boundary.
        $pkr = $this->invoice('PKR', 200000, '2026-05-01 00:00:00+00');
        $this->pay($olderUsd, 'USD', 25000, '2026-05-15 10:00:00.750+00');
        $this->pay($pkr, 'PKR', 50000, '2026-05-31 23:59:59.999+00');
        $gbp = $this->invoice('GBP', 80000, '2026-01-20 12:00:00+00');
        $gbpPayment = $this->pay($gbp, 'GBP', 80000, '2026-02-01 12:00:00+00');
        $this->refund($gbp, $gbpPayment, 'GBP', 20000, '2026-05-10 12:00:00+00');
        // EUR only expense activity.
        DB::table('finance_journals')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $this->company->id,
            'entry_type' => 'inventory_partner_payment', 'currency_code' => 'EUR',
            'amount_minor' => 3000, 'reason' => 'EUR expense only',
            'created_by' => $this->owner->id, 'created_at' => '2026-05-20 12:00:00+00',
        ]);
        $component = Livewire::test(FinanceDesk::class);
        $component->set('reportFrom', '2026-05-01')->set('reportTo', '2026-05-31');
        $summary = collect($component->instance()->financialSummary())->keyBy('currency');
        $this->assertTrue($summary->has('USD'));
        $this->assertTrue($summary->has('PKR'));
        $this->assertTrue($summary->has('EUR'));
        $this->assertTrue($summary->has('GBP'));
        $this->assertSame(0, $summary['USD']['invoiceCount']);
        $this->assertSame(25000, $summary['USD']['priorInvoicePaymentsMinor']);
        $this->assertSame(200000, $summary['PKR']['invoicedMinor']);
        $this->assertSame(50000, $summary['PKR']['periodPaymentsMinor']);
        $this->assertSame(150000, $summary['PKR']['outstandingMinor']);
        $this->assertSame(3000, $summary['EUR']['expensesMinor']);
        $this->assertSame(0, $summary['GBP']['invoiceCount']);
        $this->assertSame(20000, $summary['GBP']['periodRefundsMinor']);
    }
}
