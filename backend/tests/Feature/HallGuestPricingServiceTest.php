<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallGuestPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HallGuestPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Tier Venue', 'slug' => 'tier-venue']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Tier Hall',
            'slug' => 'tier-hall', 'capacity' => 500]);
        $owner = User::create(['name' => 'Owner', 'email' => 'tier-owner@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $this->be($owner);

        return [$company, $hall, $owner];
    }

    public function test_owner_publishes_exact_guest_tiers_and_quote_uses_the_matching_bracket(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallGuestPricingService::class);
        $revision = $service->configure($owner, $company->id, $hall->id, [
            ['minGuests' => 1, 'maxGuests' => 100, 'priceMinor' => 150000],
            ['minGuests' => 101, 'maxGuests' => 250, 'priceMinor' => 300000],
            ['minGuests' => 251, 'maxGuests' => 500, 'priceMinor' => 450000],
        ], 'PKR', 0, 'Publish exact guest-count tiers');
        $this->assertSame(1, $revision);
        $this->assertSame(150000, $service->quote($company->id, $hall->id, 100)['priceMinor']);
        $this->assertSame(300000, $service->quote($company->id, $hall->id, 101)['priceMinor']);
        $this->assertSame(1, DB::table('hall_guest_pricing_revisions')->count());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $service->quote($company->id, $hall->id, 501);
    }

    public function test_overlapping_tiers_and_stale_revision_are_rejected(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallGuestPricingService::class);
        try {
            $service->configure($owner, $company->id, $hall->id, [
                ['minGuests' => 1, 'maxGuests' => 200, 'priceMinor' => 100],
                ['minGuests' => 150, 'maxGuests' => 300, 'priceMinor' => 200],
            ], 'PKR', 0, 'Invalid overlap');
            $this->fail('Overlapping tiers accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, DB::table('hall_guest_pricing_revisions')->count());
        }
        $service->configure($owner, $company->id, $hall->id, [
            ['minGuests' => 1, 'maxGuests' => 500, 'priceMinor' => 100],
        ], 'PKR', 0, 'Initial tiers');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $service->configure($owner, $company->id, $hall->id, [
            ['minGuests' => 1, 'maxGuests' => 500, 'priceMinor' => 200],
        ], 'PKR', 0, 'Stale update');
    }
}