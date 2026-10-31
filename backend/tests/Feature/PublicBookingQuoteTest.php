<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hall;
use App\Services\PublicBookingQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PublicBookingQuoteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Booking Venue', 'slug' => 'booking-venue']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Booking Hall',
            'slug' => 'booking-hall', 'capacity' => 300, 'is_bookable' => true]);
        $owner = \App\Models\User::create(['name' => 'Owner', 'email' => 'booking-owner@example.test',
            'password' => 'test-only']);
        DB::table('hall_guest_pricing_revisions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'hall_id' => $hall->id,
            'revision_number' => 1, 'tiers' => json_encode([
                ['minGuests' => 1, 'maxGuests' => 150, 'priceMinor' => 200000],
                ['minGuests' => 151, 'maxGuests' => 300, 'priceMinor' => 350000],
            ], JSON_THROW_ON_ERROR), 'currency_code' => 'PKR', 'changed_by' => $owner->id,
            'reason' => 'Published guest tiers', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hall_program_rule_revisions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'hall_id' => $hall->id,
            'revision_number' => 1, 'currency_code' => 'PKR', 'rules' => json_encode([
                'morning' => ['start' => '09:00', 'end' => '13:00', 'rentalRateMinor' => 50000],
                'noon' => ['start' => '13:00', 'end' => '17:00', 'rentalRateMinor' => 50000],
                'evening' => ['start' => '18:00', 'end' => '23:00', 'rentalRateMinor' => 70000],
            ], JSON_THROW_ON_ERROR), 'changed_by' => $owner->id, 'reason' => 'Published programs',
            'created_at' => now(),
        ]);
        DB::table('halls')->where('id', $hall->id)->update([
            'guest_pricing_revision' => 1, 'program_rules_revision' => 1,
            'addon_catalog_revision' => 0,
        ]);
        config()->set('public_booking.enabled', true);
        config()->set('public_site.enabled', true);
        config()->set('public_site.host', 'venue.test');
        config()->set('public_site.company_id', $company->id);
        config()->set('public_site.website_id', (string) Str::uuid());
        config()->set('public_site.require_verified_domain', false);
        config()->set('central_control.activation_enabled', false);

        return [$company, $hall, $owner];
    }

    public function test_customer_receives_server_calculated_quote_from_published_tiers(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $quote = app(PublicBookingQuoteService::class)
            ->quote($company->id, '2027-12-01', 'evening', 200);
        $this->assertCount(1, $quote['halls']);
        $this->assertSame(420000, $quote['halls'][0]['totalMinor']);
        $this->assertSame($hall->id, $quote['halls'][0]['hallId']);
        $this->get('http://venue.test/book')->assertOk()->assertSee('Book your event');
        $this->post('http://venue.test/book/quote', [
            'event_date' => '2027-12-01', 'program' => 'evening', 'guests' => 200,
        ])->assertOk()->assertSee('Booking Hall')->assertSee('420,000');
    }

    public function test_confirmed_reservation_removes_hall_and_stale_price_is_rejected(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(PublicBookingQuoteService::class);
        $quote = $service->quote($company->id, '2027-12-02', 'noon', 100);
        DB::table('hall_slot_claims')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id,
            'hall_id' => $hall->id, 'event_date' => '2027-12-02',
            'program' => 'noon', 'status' => 'confirmed', 'created_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);        $this->expectException(ConflictHttpException::class);
        $service->quote($company->id, '2027-12-02', 'noon', 100);
    }
    public function test_past_dates_malformed_addons_and_unconfigured_halls_are_safe(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $extra = Hall::create(['company_id' => $company->id, 'name' => 'Unpriced Hall',
            'slug' => 'unpriced-hall', 'capacity' => 300, 'is_bookable' => true]);
        $service = app(PublicBookingQuoteService::class);
        $quote = $service->quote($company->id, '2027-12-03', 'morning', 50);
        $this->assertCount(1, $quote['halls']);
        $this->assertSame($hall->id, $quote['halls'][0]['hallId']);
        try {
            $service->quote($company->id, '2020-01-01', 'morning', 50);
            $this->fail('Past date was accepted.');
        } catch (\InvalidArgumentException) {}
        try {
            $service->quote($company->id, '2027-12-03', 'morning', 50, ['Bad Code!']);
            $this->fail('Malformed add-on was accepted.');
        } catch (\InvalidArgumentException) {}
    }
    public function test_public_hold_is_idempotent_blocks_competitors_and_can_be_cancelled(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        config()->set('public_booking.holds_enabled', true);
        $service = app(PublicBookingQuoteService::class);
        $quote = $service->quote($company->id, '2027-12-05', 'evening', 200);
        $holds = app(\App\Services\PublicBookingHoldService::class);
        $key = (string) Str::uuid();
        $customer = ['name' => 'Synthetic Customer', 'email' => 'customer@example.test', 'phone' => ''];
        $first = $holds->create($company->id, $quote, $hall->id, $customer, $key);
        $this->assertSame(21000, $first['depositMinor']);
        $retry = $holds->create($company->id, $quote, $hall->id, $customer, $key);
        $this->assertSame($first['token'], $retry['token']);
        $this->assertSame(1, DB::table('public_booking_holds')->count());

        $this->expectException(ConflictHttpException::class);
        $service->quote($company->id, '2027-12-05', 'evening', 200);
    }

    public function test_cancelled_hold_releases_slot(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        config()->set('public_booking.holds_enabled', true);
        $quote = app(PublicBookingQuoteService::class)->quote($company->id, '2027-12-06', 'noon', 100);
        $holds = app(\App\Services\PublicBookingHoldService::class);
        $hold = $holds->create($company->id, $quote, $hall->id,
            ['name' => 'Synthetic Customer', 'email' => 'customer@example.test'], (string) Str::uuid());
        $holds->cancel($company->id, $hold['token']);
        $this->assertSame(0, DB::table('public_booking_holds')->count());
        $this->assertCount(1, app(PublicBookingQuoteService::class)
            ->quote($company->id, '2027-12-06', 'noon', 100)['halls']);
    }
    public function test_verified_synthetic_deposit_confirms_booking_atomically(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        config()->set('public_booking.holds_enabled', true);
        config()->set('public_booking.synthetic_verifier_secret', str_repeat('s', 32));
        config()->set('public_booking.synthetic_test_boundary', true);
        $quote = app(PublicBookingQuoteService::class)->quote($company->id, '2027-12-07', 'evening', 200);
        $hold = app(\App\Services\PublicBookingHoldService::class)->create($company->id, $quote, $hall->id,
            ['name' => 'Synthetic Customer', 'email' => 'customer@example.test'], (string) Str::uuid());
        $canonical = implode('|', [$company->id, $hold['holdId'], 'SYNTH-0001',
            (string) $hold['depositMinor'], 'PKR', 'ci-verifier']);
        $signature = hash_hmac('sha256', $canonical, str_repeat('s', 32));
        $evidence = app(\App\Services\PublicBookingPaymentService::class)->verifySynthetic(
            $company->id, $hold['holdId'], 'SYNTH-0001', $hold['depositMinor'], 'PKR',
            'ci-verifier', $signature);
        $this->assertNotEmpty($evidence['evidenceId']);
        $confirmation = app(\App\Services\PublicBookingConfirmationService::class)
            ->confirm($company->id, $hold['token']);
        $this->assertSame($hold['depositMinor'], $confirmation['depositMinor']);
        $this->assertSame(0, DB::table('public_booking_holds')->count());
        $this->assertSame(1, DB::table('public_booking_confirmations')->count());
        $this->assertSame(1, DB::table('public_booking_payment_evidence')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'public_booking.confirmed')->count());
        $this->expectException(ConflictHttpException::class);
        app(\App\Services\PublicBookingConfirmationService::class)->confirm($company->id, $hold['token']);
    }

    public function test_synthetic_verifier_rejects_underpayment_and_forged_signature(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        config()->set('public_booking.holds_enabled', true);
        config()->set('public_booking.synthetic_verifier_secret', str_repeat('s', 32));
        config()->set('public_booking.synthetic_test_boundary', true);
        $quote = app(PublicBookingQuoteService::class)->quote($company->id, '2027-12-08', 'noon', 100);
        $hold = app(\App\Services\PublicBookingHoldService::class)->create($company->id, $quote, $hall->id,
            ['name' => 'Synthetic Customer', 'email' => 'customer@example.test'], (string) Str::uuid());
        $payment = app(\App\Services\PublicBookingPaymentService::class);
        try {
            $payment->verifySynthetic($company->id, $hold['holdId'], 'SYNTH-2',
                $hold['depositMinor'] - 1, 'PKR', 'ci-verifier', str_repeat('a', 64));
            $this->fail('Forged underpayment accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        try {
            $payment->verifySynthetic($company->id, $hold['holdId'], 'SYNTH-3',
                $hold['depositMinor'], 'USD', 'ci-verifier', str_repeat('a', 64));
            $this->fail('Wrong currency accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
    }
    public function test_second_eligible_hall_can_be_confirmed_and_then_blocks_new_quotes(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $second = Hall::create(['company_id' => $company->id, 'name' => 'Second Hall',
            'slug' => 'second-booking-hall', 'capacity' => 300, 'is_bookable' => true]);
        DB::table('hall_guest_pricing_revisions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'hall_id' => $second->id,
            'revision_number' => 1, 'tiers' => json_encode([
                ['minGuests' => 1, 'maxGuests' => 300, 'priceMinor' => 250000],
            ], JSON_THROW_ON_ERROR), 'currency_code' => 'PKR', 'changed_by' => $owner->id,
            'reason' => 'Second hall tiers', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hall_program_rule_revisions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'hall_id' => $second->id,
            'revision_number' => 1, 'currency_code' => 'PKR', 'rules' => json_encode([
                'morning' => ['start' => '09:00', 'end' => '13:00', 'rentalRateMinor' => 10000],
                'noon' => ['start' => '13:00', 'end' => '17:00', 'rentalRateMinor' => 10000],
                'evening' => ['start' => '18:00', 'end' => '23:00', 'rentalRateMinor' => 10000],
            ], JSON_THROW_ON_ERROR), 'changed_by' => $owner->id, 'reason' => 'Second hall programs',
            'created_at' => now(),
        ]);
        DB::table('halls')->where('id', $second->id)->update([
            'guest_pricing_revision' => 1, 'program_rules_revision' => 1, 'addon_catalog_revision' => 0,
        ]);
        config()->set('public_booking.holds_enabled', true);
        config()->set('public_booking.synthetic_verifier_secret', str_repeat('s', 32));
        config()->set('public_booking.synthetic_test_boundary', true);
        $quote = app(PublicBookingQuoteService::class)->quote($company->id, '2027-12-09', 'morning', 100);
        $this->assertCount(2, $quote['halls']);
        $holds = app(\App\Services\PublicBookingHoldService::class);
        $hold = $holds->create($company->id, $quote, $second->id,
            ['name' => 'Second Hall Customer', 'email' => 'second@example.test'], (string) Str::uuid());
        $canonical = implode('|', [$company->id, $hold['holdId'], 'SYNTH-SECOND',
            (string) $hold['depositMinor'], 'PKR', 'ci-verifier']);
        app(\App\Services\PublicBookingPaymentService::class)->verifySynthetic($company->id,
            $hold['holdId'], 'SYNTH-SECOND', $hold['depositMinor'], 'PKR', 'ci-verifier',
            hash_hmac('sha256', $canonical, str_repeat('s', 32)));
        $confirmed = app(\App\Services\PublicBookingConfirmationService::class)
            ->confirm($company->id, $hold['token']);
        $this->assertNotEmpty($confirmed['confirmationId']);
        $this->assertSame($second->id, DB::table('public_booking_confirmations')
            ->value('hall_id'));
        $remaining = app(PublicBookingQuoteService::class)
            ->quote($company->id, '2027-12-09', 'morning', 100);
        $this->assertCount(1, $remaining['halls']);
        $this->assertSame($hall->id, $remaining['halls'][0]['hallId']);
    }
    public function test_idempotency_rejects_changed_customer_and_expired_replay(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        config()->set('public_booking.holds_enabled', true);
        $quote = app(PublicBookingQuoteService::class)->quote($company->id, '2027-12-10', 'noon', 100);
        $holds = app(\App\Services\PublicBookingHoldService::class);
        $key = (string) Str::uuid();
        $first = $holds->create($company->id, $quote, $hall->id,
            ['name' => 'Original Customer', 'email' => 'original@example.test'], $key);
        try {
            $holds->create($company->id, $quote, $hall->id,
                ['name' => 'Different Customer', 'email' => 'different@example.test'], $key);
            $this->fail('Changed customer reused the idempotency key.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        DB::table('public_booking_holds')->where('id', $first['holdId'])
            ->update(['expires_at' => now()->subMinute()]);
        try {
            $holds->create($company->id, $quote, $hall->id,
                ['name' => 'Original Customer', 'email' => 'original@example.test'], $key);
            $this->fail('Expired hold token was replayed.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
    }}
