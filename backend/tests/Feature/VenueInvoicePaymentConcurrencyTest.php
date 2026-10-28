<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\VenueInvoicePaymentService;
use App\Services\VenueInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @group phase9-finance-process
 *
 * Runs against a disposable PostgreSQL database migrated once by CI before
 * this test. No schema rollback occurs here: immutable finance journals are
 * never put through DatabaseMigrations teardown.
 */
#[Group('phase9-finance-process')]
final class VenueInvoicePaymentConcurrencyTest extends TestCase
{
    public function test_competing_processes_cannot_double_apply_a_deposit(): void
    {
        $company = Company::create(['name' => 'Ledger Race Venue', 'slug' => 'ledger-race']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Ledger Hall',
            'slug' => 'ledger-hall', 'capacity' => 100]);
        $owner = User::create(['name' => 'Owner', 'email' => 'ledger-race@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $customer = (string) Str::uuid();
        DB::table('customer_profiles')->insert([
            'id' => $customer, 'company_id' => $company->id,
            'name_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString('Ledger Client'),
            'created_by' => $owner->id,
        ]);
        $draft = (string) Str::uuid();
        DB::table('booking_drafts')->insert([
            'id' => $draft, 'company_id' => $company->id, 'hall_id' => $hall->id,
            'customer_id' => $customer, 'event_date' => '2027-09-02',
            'program' => 'evening', 'requested_guests' => 50, 'status' => 'booked',
            'revision' => 1, 'created_by' => $owner->id,
        ]);
        $claim = (string) Str::uuid();
        DB::table('hall_slot_claims')->insert([
            'id' => $claim, 'company_id' => $company->id, 'hall_id' => $hall->id,
            'event_date' => '2027-09-02', 'program' => 'evening',
            'status' => 'confirmed', 'created_by' => $owner->id,
        ]);
        $reservation = (string) Str::uuid();
        DB::table('venue_reservations')->insert([
            'id' => $reservation, 'company_id' => $company->id,
            'source_draft_id' => $draft, 'customer_id' => $customer,
            'hall_id' => $hall->id, 'claim_id' => $claim,
            'event_date' => '2027-09-02', 'program' => 'evening',
            'guests' => 50, 'status' => 'confirmed', 'revision' => 1,
            'created_by' => $owner->id,
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
            'request_fingerprint' => str_repeat('c', 64),
            'subtotal_minor' => 150000, 'created_by' => $owner->id,
            'reason' => 'Concurrent deposit certification',
        ]);
        $quote = (string) Str::uuid();
        DB::table('venue_quote_issues')->insert([
            'id' => $quote, 'company_id' => $company->id, 'snapshot_id' => $snapshot,
            'reservation_id' => $reservation, 'number' => 1,
            'document_number' => 'Q-000000002', 'currency_code' => 'PKR',
            'subtotal_minor' => 150000, 'frozen_lines' => $frozen,
            'purpose' => 'Concurrent deposit certification',
            'issued_by' => $owner->id, 'issued_at' => now(),
        ]);
        config()->set('invoice_controls.issuance_enabled', true);
        config()->set('invoice_controls.tax_rate_bps', 0);
        config()->set('payment_controls.enabled', true);
        config()->set('finance_controls.posting_enabled', true);
        $this->be($owner);
        Auth::login($owner);
        $invoice = (string) Str::uuid();
        app(VenueInvoiceService::class)->issue($owner, $company->id, $quote,
            $invoice, 'Concurrent deposit source invoice');

        $dir = sys_get_temp_dir().'/finance-race-'.Str::uuid();
        mkdir($dir, 0700, true);
        $start = $dir.'/start';
        $workers = [];
        $paymentIds = [];
        foreach (['a', 'b'] as $worker) {
            $payment = (string) Str::uuid();
            $paymentIds[$worker] = $payment;
            $ready = $dir.'/ready-'.$worker;
            $result = $dir.'/result-'.$worker;
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/payment_worker.php'),
                    (string) $owner->id, $company->id, $invoice, $payment, '100000',
                    $ready, $start, $result],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $workers[$worker] = compact('process', 'pipes', 'ready', 'result');
        }

        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (is_file($workers['a']['ready']) && is_file($workers['b']['ready'])) {
                break;
            }
            usleep(20_000);
        }
        $this->assertFileExists($workers['a']['ready']);
        $this->assertFileExists($workers['b']['ready']);
        touch($start);

        $results = [];
        foreach ($workers as $worker => $handle) {
            $stdout = stream_get_contents($handle['pipes'][1]);
            $stderr = stream_get_contents($handle['pipes'][2]);
            fclose($handle['pipes'][1]);
            fclose($handle['pipes'][2]);
            $exit = proc_close($handle['process']);
            $this->assertSame(0, $exit, "Worker $worker failed: $stdout $stderr");
            $decoded = json_decode((string) file_get_contents($handle['result']), true);
            $this->assertIsArray($decoded, "Worker $worker produced no result");
            $this->assertContains($decoded['status'], ['paid', 'rejected'],
                "Worker $worker unexpected status: ".json_encode($decoded));
            $results[$worker] = $decoded;
        }
        $statuses = [];
        foreach ($results as $worker => $result) {
            $statuses[$worker] = $result['status'];
        }
        $this->assertSame(1, count(array_keys($statuses, 'paid', true)));
        $this->assertSame(1, count(array_keys($statuses, 'rejected', true)));

        $this->assertSame(1, DB::table('venue_invoice_payments')->count());
        $this->assertSame(100000, (int) DB::table('venue_invoice_payments')->sum('amount_minor'));
        $this->assertSame(1, DB::table('finance_journals')->count());
        $this->assertSame(2, DB::table('finance_journal_lines')->count());
        $this->assertSame(1, DB::table('finance_journal_lines')
            ->where('account_code', 'cash_on_hand')->where('side', 'debit')->count());
        $this->assertSame(1, DB::table('finance_journal_lines')
            ->where('account_code', 'customer_deposit_liability')->where('side', 'credit')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'venue_payment.deposit_recorded')->count());

        $history = app(VenueInvoicePaymentService::class)->history($owner, $company->id, $invoice);
        $this->assertSame(['totalMinor' => 150000, 'paidMinor' => 100000, 'dueMinor' => 50000],
            array_intersect_key($history, array_flip(['totalMinor', 'paidMinor', 'dueMinor'])));

        $winner = array_search('paid', $statuses, true);
        $winningPayment = $paymentIds[$winner];
        $winningReceipt = $results[$winner]['receipt'];
        $retry = app(VenueInvoicePaymentService::class)->record(
            $owner, $company->id, $invoice, $winningPayment,
            'cash', 100000, null, 'Concurrent competing cash deposit',
        );
        $this->assertSame($winningReceipt, $retry['receiptNumber']);
        $this->assertSame(1, DB::table('venue_invoice_payments')->count());

        $loser = $winner === 'a' ? 'b' : 'a';
        $this->assertSame(0, DB::table('finance_journals')
            ->where('id', $paymentIds[$loser])->count());
        $this->assertSame(0, DB::table('venue_invoice_payments')
            ->where('id', $paymentIds[$loser])->count());

        $second = (string) Str::uuid();
        $recovery = app(VenueInvoicePaymentService::class)->record(
            $owner, $company->id, $invoice, $second,
            'bank', 50000, 'BANK-FINANCE-RACE-1', 'Recovery transfer for remaining balance',
        );
        $this->assertSame('RCT-000000002', $recovery['receiptNumber']);
        $final = app(VenueInvoicePaymentService::class)->history($owner, $company->id, $invoice);
        $this->assertSame(150000, $final['paidMinor']);
        $this->assertSame(0, $final['dueMinor']);
        $this->assertSame(2, DB::table('finance_journals')->count());
        $this->assertSame(4, DB::table('finance_journal_lines')->count());

        $otherCompany = Company::create(['name' => 'Other Venue', 'slug' => 'other-venue-race']);
        $otherOwner = User::create(['name' => 'Other Owner', 'email' => 'other-race@example.test',
            'password' => 'test-only']);
        $otherOwner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $otherCompany->id,
            'user_id' => $otherOwner->id, 'role' => 'owner']);
        Auth::login($otherOwner);
        try {
            app(VenueInvoicePaymentService::class)->record(
                $otherOwner, $otherCompany->id, $invoice, (string) Str::uuid(),
                'cash', 1, null, 'Cross-tenant invoice probe',
            );
            $this->fail('Cross-tenant invoice deposit was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame(2, DB::table('venue_invoice_payments')->count());
        }

        try {
            DB::table('audit_events')->where('action', 'venue_payment.deposit_recorded')
                ->update(['action' => 'venue_payment.tampered']);
            $this->fail('Immutable payment audit event was updated.');
        } catch (QueryException) {
            $this->assertSame(2, DB::table('audit_events')
                ->where('action', 'venue_payment.deposit_recorded')->count());
        }
    }
}
