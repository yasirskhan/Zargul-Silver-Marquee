<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\VenueInvoiceService;
use App\Services\VenueInvoicePaymentService;
use App\Services\VenueInvoiceRefundService;
use App\Services\ApprovalWorkflowService;
use App\Models\HallAssignment;
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

    public function test_two_gated_partial_deposits_update_balance_and_exact_receipts_in_shared_ledger(): void
    {
        [$company, $owner, , $reservation, $quote] = $this->fixture();
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 1000);
        $invoiceId = (string) Str::uuid();
        app(VenueInvoiceService::class)->issue($owner, $company->id,
            $quote, $invoiceId, 'Synthetic source invoice for deposit trial');

        $payments = app(VenueInvoicePaymentService::class);
        $one = (string) Str::uuid();
        try {
            $payments->record($owner, $company->id, $invoiceId, $one,
                'cash', 60000, null, 'First synthetic cash deposit');
            $this->fail('Finance gates unexpectedly enabled.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::table('finance_journals')->count());
        }
        config()->set('payment_controls.enabled', true);
        config()->set('finance_controls.posting_enabled', true);
        $receipt = $payments->record($owner, $company->id, $invoiceId, $one,
            'cash', 60000, null, 'First synthetic cash deposit');
        $this->assertSame('RCT-000000001', $receipt['receiptNumber']);
        $this->assertSame($receipt, $payments->record($owner, $company->id, $invoiceId, $one,
            'cash', 60000, null, 'First synthetic cash deposit'));
        $this->assertSame(['totalMinor' => 165000, 'paidMinor' => 60000, 'dueMinor' => 105000],
            array_intersect_key($payments->history($owner, $company->id, $invoiceId),
                array_flip(['totalMinor', 'paidMinor', 'dueMinor'])));
        $second = (string) Str::uuid();
        $bank = $payments->record($owner, $company->id, $invoiceId, $second,
            'bank', 105000, 'BANK-TEST-0001', 'Second synthetic clearing transfer');
        $this->assertSame('RCT-000000002', $bank['receiptNumber']);
        $balance = $payments->history($owner, $company->id, $invoiceId);
        $this->assertSame(0, $balance['dueMinor']);
        $this->assertSame(165000, $balance['paidMinor']);
        $this->assertSame(2, DB::table('finance_journals')->count());
        $this->assertSame(4, DB::table('finance_journal_lines')->count());
        $this->assertSame(1, DB::table('finance_journal_lines')
            ->where('account_code', 'cash_on_hand')->where('side', 'debit')->count());
        $this->assertSame(1, DB::table('finance_journal_lines')
            ->where('account_code', 'bank_clearing')->where('side', 'debit')->count());
        $this->assertSame(2, DB::table('finance_journal_lines')
            ->where('account_code', 'customer_deposit_liability')->where('side', 'credit')->count());
        $this->assertSame(2, DB::table('audit_events')
            ->where('action', 'venue_payment.deposit_recorded')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->where('status', 'held')->count());
    }

    public function test_owner_record_deposit_from_invoice_panel_updates_due_and_receipt_without_overpayment(): void
    {
        [$company, $owner, , $reservation, $quote] = $this->fixture();
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 0);
        $invoice = (string) Str::uuid();
        app(VenueInvoiceService::class)->issue($owner, $company->id, $quote,
            $invoice, 'Synthetic controlled invoice before deposit');

        Livewire::test(VenueReservations::class)
            ->call('selectReservation', $reservation)
            ->assertSee('remaining balance 150000')
            ->assertDontSee('Record controlled invoice deposit');
        config()->set('payment_controls.enabled', true);
        config()->set('finance_controls.posting_enabled', true);
        Livewire::test(VenueReservations::class)
            ->call('selectReservation', $reservation)
            ->assertSee('Record controlled invoice deposit')
            ->set('paymentAmountMinor', '50000')
            ->set('paymentMethod', 'cash')
            ->set('paymentReason', 'Synthetic collected and counted deposit')
            ->call('recordInvoicePayment', $invoice)->assertHasNoErrors()
            ->assertSee('RCT-000000001')
            ->assertSee('remaining balance 100000');
        $this->assertSame(1, DB::table('venue_invoice_payments')->count());
        $this->assertSame(2, DB::table('finance_journal_lines')->count());
        $this->assertSame(0, DB::table('finance_cash_closeouts')->count());
    }

    public function test_overpayment_duplicate_payment_key_and_unauthorized_manager_are_denied(): void
    {
        [$company, $owner, $manager, , $quote] = $this->fixture();
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 0);
        $invoice = (string) Str::uuid();
        app(VenueInvoiceService::class)->issue($owner, $company->id,
            $quote, $invoice, 'Synthetic limited invoice');
        config()->set('payment_controls.enabled', true);
        config()->set('finance_controls.posting_enabled', true);
        $service = app(VenueInvoicePaymentService::class);
        $key = (string) Str::uuid();
        $service->record($owner, $company->id, $invoice, $key,
            'cash', 100000, null, 'Synthetic first collection');
        foreach ([
            [$key, 90000, null],
            [(string) Str::uuid(), 60000, null],
        ] as [$attempt, $amount, $reference]) {
            try {
                $service->record($owner, $company->id, $invoice, $attempt,
                    'cash', $amount, $reference, 'Synthetic invalid retry');
                $this->fail('Conflicting key or overpayment was accepted.');
            } catch (ConflictHttpException) {
                $this->assertSame(1, DB::table('venue_invoice_payments')->count());
            }
        }
        $this->be($manager);
        $this->expectException(AuthorizationException::class);
        $service->record($manager, $company->id, $invoice, (string) Str::uuid(),
            'cash', 100, null, 'Manager cannot post customer money');
    }

    public function test_confirmed_manager_refund_request_owner_decision_then_exact_receipt_reversal(): void
    {
        [$company, $owner, $manager, $reservation, $quote] = $this->fixture();
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 0);
        $invoice = (string) Str::uuid();
        app(VenueInvoiceService::class)->issue($owner, $company->id, $quote, $invoice,
            'Confirmed synthetic invoice requiring later correction');
        config()->set('payment_controls.enabled', true);
        config()->set('finance_controls.posting_enabled', true);
        $payment = (string) Str::uuid();
        $payments = app(VenueInvoicePaymentService::class);
        $payments->record($owner, $company->id, $invoice, $payment, 'cash',
            40000, null, 'Synthetic cash deposit to be refunded');

        $reservationRow = DB::table('venue_reservations')->where('id', $reservation)->first();
        $managerMember = CompanyMembership::query()
            ->where('company_id', $company->id)->where('user_id', $manager->id)->firstOrFail();
        HallAssignment::create(['company_id'=>$company->id,
            'hall_id'=>$reservationRow->hall_id,'membership_id'=>$managerMember->id]);
        $this->be($manager);
        $approval = (string) Str::uuid();
        $workflow = app(ApprovalWorkflowService::class);
        $workflow->request($manager, $company->id, $approval, 'refund', 40000,
            'PKR', $reservationRow->source_draft_id, 'Manager refund of synthetic deposit');

        $this->be($owner);
        $service = app(VenueInvoiceRefundService::class);
        try {
            $service->record($owner, $company->id, $payment, (string) Str::uuid(),
                $approval, 'Synthetic refund before separate decision');
            $this->fail('Pending refund applied to actual ledger.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::table('venue_invoice_refunds')->count());
        }
        $workflow->decide($owner, $company->id, $approval, 1, 'approved',
            'Owner authorizes exact synthetic cash refund');
        config()->set('refund_controls.enabled', true);
        $refundId = (string) Str::uuid();
        $refund = $service->record($owner, $company->id, $payment, $refundId,
            $approval, 'Synthetic verified refund handover');
        $this->assertSame('RF-000000001', $refund['refundNumber']);
        $this->assertSame($refund, $service->record($owner, $company->id,
            $payment, $refundId, $approval, 'Synthetic verified refund handover'));
        $history = $payments->history($owner, $company->id, $invoice);
        $document = app(\App\Services\VenueDocumentPdfService::class)
            ->refund($owner, $company->id, $invoice, $refundId);
        $this->assertStringStartsWith('%PDF-1.4', $document);
        $this->assertStringContainsString('RF-000000001', $document);
        $this->assertStringContainsString('NOT AUTHORIZED FOR CUSTOMER DELIVERY', $document);
        $this->assertStringContainsString('%%EOF', $document);
        $this->assertSame(40000, $history['refundedMinor']);
        $this->assertSame(0, $history['paidMinor']);
        $this->assertSame(150000, $history['dueMinor']);
        $this->assertSame(2, DB::table('finance_journals')->count());
        $this->assertSame(4, DB::table('finance_journal_lines')->count());
        $this->assertSame(1, DB::table('finance_journals')
            ->where('entry_type','reversal')->where('reversal_of',$payment)->count());
        $this->assertSame(1, DB::table('approval_decisions')->count());
        Livewire::test(VenueReservations::class)
            ->call('selectReservation', $reservation)
            ->assertSee('RF-000000001')
            ->assertSee('Download internal approved-refund PDF')
            ->assertSee('remaining balance 150000');
        $this->expectException(ConflictHttpException::class);
        $service->record($owner, $company->id, $payment, (string) Str::uuid(),
            $approval, 'Another invalid synthetic full refund');
    }

    public function test_daily_customer_receipt_reconciliation_tracks_refund_and_existing_ledger_per_currency(): void
    {
        [$company, $owner, $manager, $reservation, $quote] = $this->fixture();
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 0);
        $invoice = (string) Str::uuid();
        app(VenueInvoiceService::class)->issue($owner, $company->id, $quote, $invoice,
            'Synthetic day reconciliation invoice');
        config()->set('payment_controls.enabled', true);
        config()->set('finance_controls.posting_enabled', true);
        $cash = (string) Str::uuid();
        $bank = (string) Str::uuid();
        $payments = app(VenueInvoicePaymentService::class);
        $payments->record($owner, $company->id, $invoice, $cash,
            'cash', 20000, null, 'Synthetic daily cash deposit');
        $payments->record($owner, $company->id, $invoice, $bank,
            'bank', 30000, 'REF-TEST-100', 'Synthetic daily bank deposit');
        $service = app(\App\Services\VenueDailyReconciliationService::class);
        $today = now('UTC')->toDateString();
        $initial = $service->forDate($owner, $company->id, $today);
        $this->assertSame(2, $initial['receiptCount']);
        $this->assertSame(20000, $initial['currencies'][0]['netCashMinor']);
        $this->assertSame(30000, $initial['currencies'][0]['netBankMinor']);
        $this->assertTrue($initial['currencies'][0]['ledgerMatched']);

        $row = DB::table('venue_reservations')->where('id', $reservation)->first();
        $member = CompanyMembership::query()->where('company_id', $company->id)
            ->where('user_id', $manager->id)->firstOrFail();
        HallAssignment::create(['company_id' => $company->id,
            'hall_id' => $row->hall_id, 'membership_id' => $member->id]);
        $this->be($manager);
        $approvalId = (string) Str::uuid();
        app(ApprovalWorkflowService::class)->request($manager, $company->id,
            $approvalId, 'refund', 20000, 'PKR', $row->source_draft_id,
            'Synthetic manager cash refund approval');
        $this->be($owner);
        app(ApprovalWorkflowService::class)->decide($owner, $company->id,
            $approvalId, 1, 'approved', 'Synthetic owner refund authority');
        config()->set('refund_controls.enabled', true);
        app(VenueInvoiceRefundService::class)->record($owner, $company->id,
            $cash, (string) Str::uuid(), $approvalId, 'Verified synthetic refund correction');

        $day = $service->forDate($owner, $company->id, $today);
        $this->assertSame(1, $day['refundCount']);
        $this->assertSame(20000, $day['currencies'][0]['cashRefundedMinor']);
        $this->assertSame(0, $day['currencies'][0]['netCashMinor']);
        $this->assertSame(30000, $day['currencies'][0]['netBankMinor']);
        $this->assertTrue($day['currencies'][0]['ledgerMatched']);
        $this->assertSame(2, $day['currencies'][0]['receiptCount']);
        $this->assertSame(1, $day['currencies'][0]['refundCount']);
        $yesterday = now('UTC')->subDay()->toDateString();
        $this->assertSame([], $service->forDate($owner, $company->id, $yesterday)['currencies']);
        Livewire::test(VenueReservations::class)->assertSee('Reservations');
        $this->assertSame(3, DB::table('finance_journals')->count());
        $this->assertSame(6, DB::table('finance_journal_lines')->count());
        $this->assertSame([], $day['cashCloseouts']);
        $this->assertSame($today, $day['utcDate']);

        $this->be($manager);
        $this->expectException(AuthorizationException::class);
        $service->forDate($manager, $company->id, $today);
    }

    public function test_internal_invoice_and_receipt_pdf_are_valid_private_read_only_documents(): void
    {
        [$company, $owner, $manager, $reservation, $quote] = $this->fixture();
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 0);
        $invoice = (string) Str::uuid();
        app(VenueInvoiceService::class)->issue($owner, $company->id, $quote, $invoice,
            'Synthetic internal PDF export invoice');
        config()->set('payment_controls.enabled', true);
        config()->set('finance_controls.posting_enabled', true);
        $receipt = (string) Str::uuid();
        app(VenueInvoicePaymentService::class)->record($owner, $company->id,
            $invoice, $receipt, 'bank', 15000, 'BANK-PDF-TEST',
            'Synthetic bank receipt internal document');
        $pdf = app(\App\Services\VenueDocumentPdfService::class);
        $invoiceBytes = $pdf->invoice($owner, $company->id, $invoice);
        $receiptBytes = $pdf->receipt($owner, $company->id, $invoice, $receipt);
        foreach ([$invoiceBytes, $receiptBytes] as $bytes) {
            $this->assertStringStartsWith('%PDF-1.4', $bytes);
            $this->assertStringContainsString('startxref', $bytes);
            $this->assertStringContainsString('%%EOF', $bytes);
            $this->assertStringContainsString('NOT AUTHORIZED FOR CUSTOMER DELIVERY', $bytes);
            $this->assertStringNotContainsString('invoice-owner@example.test', $bytes);
        }
        $this->assertStringContainsString('INV-000000001', $invoiceBytes);
        $this->assertStringContainsString('RCT-000000001', $receiptBytes);
        Livewire::test(VenueReservations::class)->call('selectReservation', $reservation)
            ->assertSee('Download internal invoice PDF')
            ->assertSee('Download internal receipt PDF');
        $this->assertSame(1, DB::table('venue_invoice_payments')->count());
        $this->assertSame(1, DB::table('finance_journals')->count());

        $this->be($manager);
        $this->expectException(AuthorizationException::class);
        $pdf->invoice($manager, $company->id, $invoice);
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
