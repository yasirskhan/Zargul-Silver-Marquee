<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\VenueQuoteIssueService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class VenueQuoteIssueServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Internal Quote Issue Venue', 'slug' => 'internal-quote-issue']);
        $hall = Hall::create(['company_id' => $company->id,
            'name' => 'Synthetic Hall', 'slug' => 'quote-issue-hall', 'capacity' => 200]);
        $owner = User::create(['name' => 'Owner', 'email' => 'quote-issue-owner@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $manager = User::create(['name' => 'Manager', 'email' => 'quote-issue-manager@example.test',
            'password' => 'test-only']);
        $manager->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $manager->id, 'role' => 'manager']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $customer = (string) Str::uuid();
        DB::table('customer_profiles')->insert([
            'id' => $customer, 'company_id' => $company->id,
            'name_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString('Synthetic Quote Guest'),
            'created_by' => $owner->id,
        ]);
        $draft = (string) Str::uuid();
        DB::table('booking_drafts')->insert([
            'id' => $draft, 'company_id' => $company->id,
            'hall_id' => $hall->id, 'customer_id' => $customer,
            'event_date' => '2027-08-01', 'program' => 'evening',
            'requested_guests' => 100, 'status' => 'booked',
            'revision' => 3, 'created_by' => $owner->id,
        ]);
        $claim = (string) Str::uuid();
        DB::table('hall_slot_claims')->insert([
            'id' => $claim, 'company_id' => $company->id, 'hall_id' => $hall->id,
            'event_date' => '2027-08-01', 'program' => 'evening',
            'status' => 'confirmed', 'created_by' => $owner->id,
        ]);
        $reservation = (string) Str::uuid();
        DB::table('venue_reservations')->insert([
            'id' => $reservation, 'company_id' => $company->id, 'hall_id' => $hall->id,
            'source_draft_id' => $draft, 'customer_id' => $customer, 'claim_id' => $claim,
            'event_date' => '2027-08-01', 'program' => 'evening',
            'guests' => 100, 'status' => 'confirmed', 'revision' => 1, 'created_by' => $owner->id,
        ]);
        $snapshot = (string) Str::uuid();
        DB::table('venue_quote_snapshots')->insert([
            'id' => $snapshot, 'company_id' => $company->id,
            'reservation_id' => $reservation, 'reservation_revision' => 1,
            'hall_id' => $hall->id, 'program_revision' => 1,
            'seating_revision' => 1, 'addon_revision' => 0,
            'currency_code' => 'PKR', 'subtotal_minor' => 150000,
            'frozen_lines' => json_encode([['code' => 'hall_rental',
                'quantity' => 1, 'rateMinor' => 150000, 'amountMinor' => 150000]],
                JSON_THROW_ON_ERROR),
            'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $owner->id, 'reason' => 'Synthetic frozen base rental',
        ]);
        return [$company, $hall, $owner, $manager, $reservation, $snapshot];
    }

    public function test_numbered_internal_quote_requires_release_gate_and_is_idempotent_and_audited(): void
    {
        [$company, , $owner, , $reservation, $snapshot] = $this->fixture();
        $service = app(VenueQuoteIssueService::class);
        $id = (string) Str::uuid();
        try {
            $service->issue($owner, $company->id, $snapshot, $id,
                'Synthetic private issue without release');
            $this->fail('Operator quote release gate was bypassed.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::table('venue_quote_issues')->count());
        }
        config()->set('quote_controls.issuance_enabled', true);
        $first = $service->issue($owner, $company->id, $snapshot, $id,
            'Synthetic private issue after explicit test gate');
        $this->assertSame('Q-000000001', $first['number']);
        $this->assertSame(150000, $first['subtotalMinor']);
        $this->assertSame($first, $service->issue($owner, $company->id, $snapshot, $id,
            'Synthetic private issue after explicit test gate'));
        $this->assertSame([$id], array_column($service->list(
            $owner, $company->id, $reservation), 'id'));
        $this->assertSame(1, DB::table('venue_quote_issues')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'venue_quote.internal_issued')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_owner_only_and_stale_reservation_deny_internal_numbered_quote_issue(): void
    {
        [$company, , $owner, $manager, $reservation, $snapshot] = $this->fixture();
        config()->set('quote_controls.issuance_enabled', true);
        $this->be($manager);
        try {
            app(VenueQuoteIssueService::class)->issue($manager, $company->id,
                $snapshot, (string) Str::uuid(), 'Manager unauthorized issue');
            $this->fail('Manager issued an owner-only private quote.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($owner);
        DB::table('venue_reservations')->where('id', $reservation)->update(['revision' => 2]);
        $this->expectException(ConflictHttpException::class);
        app(VenueQuoteIssueService::class)->issue($owner, $company->id,
            $snapshot, (string) Str::uuid(), 'Stale quote cannot issue after reschedule');
    }

    public function test_issued_numbered_quote_history_is_immutable(): void
    {
        [$company, , $owner, , , $snapshot] = $this->fixture();
        config()->set('quote_controls.issuance_enabled', true);
        $id = (string) Str::uuid();
        app(VenueQuoteIssueService::class)->issue($owner, $company->id, $snapshot, $id,
            'Synthetic immutable numbered internal quote');
        $this->expectException(QueryException::class);
        DB::table('venue_quote_issues')->where('id', $id)->update(['subtotal_minor' => 0]);
    }
}
