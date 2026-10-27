<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\VenueInvoicePaymentService;
use App\Services\VenueInvoiceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Two real PHP processes post competing deposits against one invoice.
 * The company-row lock must serialize them: one receives a receipt, the
 * other must fail closed on the remaining balance. No double application.
 */
final class ZzzVenueInvoicePaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        parent::tearDown();
        Artisan::call('migrate:fresh');
    }

    public function test_competing_processes_cannot_double_apply_a_deposit(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Financial process race requires pcntl_fork');
        }
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
        \Illuminate\Support\Facades\Auth::login($owner);
        $invoice = (string) Str::uuid();
        app(VenueInvoiceService::class)->issue($owner, $company->id, $quote,
            $invoice, 'Concurrent deposit source invoice');

        DB::purge();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        [$parentSocket, $childSocket] = $pair;
        $resultFile = tempnam(sys_get_temp_dir(), 'payment-race-');
        $this->assertNotFalse($resultFile);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            DB::purge();
            fgets($childSocket);
            try {
                app(VenueInvoicePaymentService::class)->record(
                    $owner, $company->id, $invoice, (string) Str::uuid(),
                    'cash', 100000, null, 'Concurrent competing cash deposit',
                );
                file_put_contents($resultFile, 'paid');
            } catch (ConflictHttpException) {
                file_put_contents($resultFile, 'rejected');
            }
            fclose($childSocket);
            exit(0);
        }
        fclose($childSocket);
        fwrite($parentSocket, "go\n");
        try {
            app(VenueInvoicePaymentService::class)->record(
                $owner, $company->id, $invoice, (string) Str::uuid(),
                'cash', 100000, null, 'Concurrent competing cash deposit',
            );
            $parent = 'paid';
        } catch (ConflictHttpException) {
            $parent = 'rejected';
        }
        pcntl_waitpid($pid, $status);
        $child = trim((string) file_get_contents($resultFile));
        fclose($parentSocket);
        @unlink($resultFile);
        $this->assertSame(1, [$parent, $child] === ['paid', 'rejected']
            || [$parent, $child] === ['rejected', 'paid'] ? 1 : 0);
        $this->assertSame(1, DB::table('venue_invoice_payments')->count());
        $this->assertSame(100000, (int) DB::table('venue_invoice_payments')->sum('amount_minor'));
        $this->assertSame(1, DB::table('finance_journals')->count());
        $this->assertSame(2, DB::table('finance_journal_lines')->count());
        $history = app(VenueInvoicePaymentService::class)->history($owner, $company->id, $invoice);
        $this->assertSame(100000, $history['paidMinor']);
        $this->assertSame(50000, $history['dueMinor']);
    }
}
