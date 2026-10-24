<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\VenueEnquiryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class VenueEnquiryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver Marquee', 'slug' => 'enquiry-company']);
        $foreign = Company::create(['name' => 'Other Venue', 'slug' => 'enquiry-foreign']);
        $websiteId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $websiteId, 'company_id' => $company->id, 'name' => 'Silver Site',
        ]);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand Hall',
            'slug' => 'enquiry-grand', 'capacity' => 300,
        ]);
        $foreignHall = Hall::create([
            'company_id' => $foreign->id, 'name' => 'Foreign Hall',
            'slug' => 'enquiry-foreign-hall', 'capacity' => 100,
        ]);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'enquiry-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);

        return [$company, $foreign, $websiteId, $hall, $foreignHall, $owner];
    }

    private function payload(string $hallId, ?string $key = null): array
    {
        return [
            'requestKey' => $key ?? (string) Str::uuid(),
            'customerName' => 'Synthetic Enquiry Customer',
            'email' => 'enquiry@example.test',
            'phone' => '+1 555 0100',
            'hallId' => $hallId,
            'eventDate' => now()->addDays(20)->toDateString(),
            'program' => 'evening',
            'guests' => 120,
            'message' => 'Synthetic private enquiry without booking',
            'consent' => true,
        ];
    }

    public function test_public_enquiry_is_private_idempotent_and_routes_to_owner_triage(): void
    {
        [$company, , $websiteId, $hall, $foreignHall, $owner] = $this->fixture();
        $service = app(VenueEnquiryService::class);
        $key = (string) Str::uuid();
        $first = $service->submit($company->id, $websiteId, $this->payload($hall->id, $key), '127.0.0.1');
        $duplicate = $service->submit($company->id, $websiteId, $this->payload($hall->id, $key), '127.0.0.1');
        $this->assertFalse($first['duplicate']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first['id'], $duplicate['id']);
        $this->assertSame(1, DB::table('venue_enquiries')->count());
        $this->assertSame('new', DB::table('venue_enquiries')->value('status'));
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'public.enquiry_received')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('venue_reservations')->count());

        try {
            $service->submit($company->id, $websiteId, $this->payload($foreignHall->id));
            $this->fail('Foreign hall enquiry was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $noConsent = $this->payload($hall->id);
        $noConsent['consent'] = false;
        try {
            $service->submit($company->id, $websiteId, $noConsent);
            $this->fail('Enquiry without consent was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, DB::table('venue_enquiries')->count());

        $rows = $service->list($owner, $company->id);
        $this->assertCount(1, $rows);
        $this->assertSame('Grand Hall', $rows[0]['hall_name']);
        $service->updateStatus($owner, $company->id, $first['id'], 'contacted',
            'Owner contacted the synthetic customer');
        $this->assertSame('contacted', DB::table('venue_enquiries')->value('status'));
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'public_enquiry.status_updated')->count());

        $manager = User::create([
            'name' => 'Manager', 'email' => 'enquiry-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        try {
            $service->list($manager, $company->id);
            $this->fail('Manager read private enquiries.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }
}
