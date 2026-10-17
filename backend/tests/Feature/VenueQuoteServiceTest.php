<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Filament\Pages\VenueReservations;
use App\Services\BookingDraftReviewService;
use App\Services\BookingDraftService;
use App\Services\CustomerProfileService;
use App\Services\HallAddonCatalogService;
use App\Services\HallProgramRulesService;
use App\Services\HallSeatingPriceService;
use App\Services\VenueQuoteService;
use App\Services\VenueReservationService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class VenueQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function setupBooking(): array
    {
        $company = Company::create(['name' => 'Quote Test Venue', 'slug' => 'quote-test-venue']);
        $hall = Hall::create(['company_id' => $company->id,
            'name' => 'Quote Hall', 'slug' => 'quote-hall', 'capacity' => 200]);
        $owner = User::create(['name' => 'Quote Owner',
            'email' => 'quote-owner@example.test', 'password' => 'test-password']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $outsider = User::create(['name' => 'Outsider',
            'email' => 'quote-outsider@example.test', 'password' => 'test-password']);
        $outsider->markEmailAsVerified();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $slot = static fn (string $a, string $b): array => [
            'start' => $a, 'end' => $b, 'setupMinutes' => 10,
            'cleaningMinutes' => 10, 'graceMinutes' => 10,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
            'rentalRateMinor' => 100000,
        ];
        app(HallProgramRulesService::class)->configure($owner, $company->id, $hall->id, [
            'morning' => $slot('08:00', '11:00'),
            'noon' => $slot('12:00', '15:00'),
            'evening' => $slot('17:00', '22:00'),
        ], 'PKR', 0, 'Synthetic rental price setup');
        app(HallSeatingPriceService::class)->configure(
            $owner, $company->id, $hall->id, [
                'capacity' => 200, 'includedChairs' => 150, 'includedTables' => 20,
                'extraChairRateMinor' => 500, 'extraTableRateMinor' => 1000,
            ], 'PKR', 0, 'Synthetic seating price setup');
        app(HallAddonCatalogService::class)->configure($owner, $company->id, $hall->id, [
            ['code' => 'music', 'name' => 'Live Music', 'category' => 'music',
                'chargeUnit' => 'flat', 'rateMinor' => 20000, 'enabled' => true],
            ['code' => 'catering', 'name' => 'Dinner', 'category' => 'catering',
                'chargeUnit' => 'per_guest', 'rateMinor' => 1500, 'enabled' => true],
        ], 'PKR', 0, 'Synthetic optional service rates');
        $customer = (string) Str::uuid();
        app(CustomerProfileService::class)->create($owner, $company->id, $customer,
            'Synthetic Quote Guest', 'private-quote@example.test', null, 'Test only customer');
        $draft = (string) Str::uuid();
        app(BookingDraftService::class)->create($owner, $company->id, $draft, $hall->id,
            $customer, '2027-06-10', 'evening', 100, 'Internal quote booking');
        app(BookingDraftReviewService::class)->submit($owner, $company->id, $draft, 1,
            'Synthetic owner proposal review');
        config()->set('booking_controls.confirmation_enabled', true);
        $reservation = app(VenueReservationService::class)->confirm(
            $owner, $company->id, $draft, (string) Str::uuid(),
            (string) Str::uuid(), 2, 'Approved internal session reservation');
        return [$company, $hall, $owner, $outsider, $reservation];
    }

    public function test_full_internal_quote_snapshots_rental_seating_catering_and_music_without_posting(): void
    {
        [$company, , $owner, , $reservation] = $this->setupBooking();
        $service = app(VenueQuoteService::class);
        $id = (string) Str::uuid();
        $args = [$owner, $company->id, $reservation['id'], $id, 1, 10, 3,
            [['code' => 'music', 'quantity' => 1], ['code' => 'catering', 'quantity' => 1]],
            'Synthetic complete internal frozen quote'];
        $first = $service->create(...$args);
        $this->assertSame('internal_nonbinding', $first['status']);
        $this->assertSame('PKR', $first['currency']);
        $this->assertSame(278000, $first['subtotalMinor']);
        $this->assertCount(5, $first['lines']);
        $this->assertSame($first, $service->create(...$args));
        $changed = $args;
        $changed[5] = 11;
        try {
            $service->create(...$changed);
            $this->fail('Retry key accepted a different seating quantity.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->assertSame([$id], array_column($service->list($owner,
            $company->id, $reservation['id']), 'id'));
        $this->assertSame(1, DB::table('venue_quote_snapshots')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'venue_quote.snapshot_created')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertStringNotContainsString('private-quote@example.test',
            json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function test_quote_rejects_unauthorized_scope_excess_capacity_and_stale_revision(): void
    {
        [$company, , $owner, $outsider, $reservation] = $this->setupBooking();
        $service = app(VenueQuoteService::class);
        try {
            $service->create($owner, $company->id, $reservation['id'],
                (string) Str::uuid(), 1, 51, 0, [], 'Exceeds hall seating');
            $this->fail('Excessive extra seating was accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        try {
            $service->create($owner, $company->id, $reservation['id'],
                (string) Str::uuid(), 2, 0, 0, [], 'Stale reservation version');
            $this->fail('Stale reservation revision accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->be($outsider);
        $this->expectException(AuthorizationException::class);
        $service->list($outsider, $company->id, $reservation['id']);
    }

    public function test_owner_can_build_hall_seating_music_and_catering_quote_in_reservations_panel(): void
    {
        [$company, , $owner, $outsider, $reservation] = $this->setupBooking();
        $panel = \Livewire\Livewire::test(VenueReservations::class)
            ->call('selectReservation', $reservation['id'])
            ->assertSee('Build complete internal rental price snapshot')
            ->assertSee('Live Music')
            ->set('quoteExtraChairs', '10')
            ->set('quoteExtraTables', '3')
            ->set('quoteAddonQuantities', ['music' => '1', 'catering' => '1'])
            ->set('quoteReason', 'Full synthetic owner price build')
            ->call('freezeQuote')->assertHasNoErrors()
            ->assertSee('278000')->assertSee('hall_rental');
        $this->assertCount(1, $panel->get('quoteSnapshots'));
        $this->assertSame(278000, DB::table('venue_quote_snapshots')->value('subtotal_minor'));
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->be($outsider);
        \Livewire\Livewire::test(VenueReservations::class)->assertForbidden();
    }

    public function test_immutable_quote_cannot_be_silently_edited(): void
    {
        [$company, , $owner, , $reservation] = $this->setupBooking();
        $id = (string) Str::uuid();
        app(VenueQuoteService::class)->create($owner, $company->id,
            $reservation['id'], $id, 1, 0, 0, [], 'Freeze basic venue rental');
        $this->expectException(QueryException::class);
        DB::table('venue_quote_snapshots')->where('id', $id)
            ->update(['subtotal_minor' => 0]);
    }
}
