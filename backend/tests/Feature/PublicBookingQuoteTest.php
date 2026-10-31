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
}