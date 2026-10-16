<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hall;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingDraftSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Venue A', 'slug' => 'booking-schema-a']);
        $foreign = Company::create(['name' => 'Venue B', 'slug' => 'booking-schema-b']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Hall A',
            'slug' => 'a', 'capacity' => 300,
        ]);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'booking-draft-schema@example.test', 'password' => 'test-only',
        ]);
        $customerId = (string) Str::uuid();
        DB::table('customer_profiles')->insert([
            'id' => $customerId, 'company_id' => $company->id,
            'name_ciphertext' => str_repeat('opaque-', 5),
            'created_by' => $owner->id,
        ]);

        return [$company, $foreign, $hall, $owner, $customerId];
    }

    private function draft(string $companyId, string $hallId, string $customerId, int $actorId, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'hall_id' => $hallId, 'customer_id' => $customerId,
            'event_date' => '2026-12-15', 'program' => 'evening',
            'requested_guests' => 200, 'status' => 'draft',
            'revision' => 1, 'created_by' => $actorId,
        ], $overrides);
    }

    public function test_internal_drafts_are_preconfirmation_and_do_not_touch_hall_claims(): void
    {
        [$company, , $hall, $owner, $customerId] = $this->fixture();
        $first = $this->draft($company->id, $hall->id, $customerId, $owner->id);
        $second = $this->draft($company->id, $hall->id, $customerId, $owner->id);
        DB::table('booking_drafts')->insert([$first, $second]);
        $this->assertSame(2, DB::table('booking_drafts')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('hall_slot_claim_events')->count());
    }

    public function test_draft_cannot_reference_another_company_customer(): void
    {
        [$company, $foreign, $hall, $owner, $customerId] = $this->fixture();
        $this->expectException(QueryException::class);
        DB::table('booking_drafts')->insert($this->draft(
            $foreign->id, $hall->id, $customerId, $owner->id
        ));
    }

    public function test_draft_cannot_reference_another_company_hall(): void
    {
        [$company, $foreign, $hall, $owner, $customerId] = $this->fixture();
        $foreignHall = Hall::create([
            'company_id' => $foreign->id, 'name' => 'Hall B',
            'slug' => 'b', 'capacity' => 100,
        ]);
        $this->expectException(QueryException::class);
        DB::table('booking_drafts')->insert($this->draft(
            $company->id, $foreignHall->id, $customerId, $owner->id
        ));
    }

    public function test_schema_rejects_confirmed_state_without_approved_booking_service(): void
    {
        [$company, , $hall, $owner, $customerId] = $this->fixture();
        $this->expectException(QueryException::class);
        DB::table('booking_drafts')->insert($this->draft(
            $company->id, $hall->id, $customerId, $owner->id,
            ['status' => 'confirmed']
        ));
    }

    public function test_schema_rejects_invalid_program_or_guest_count(): void
    {
        [$company, , $hall, $owner, $customerId] = $this->fixture();
        $this->expectException(QueryException::class);
        DB::table('booking_drafts')->insert($this->draft(
            $company->id, $hall->id, $customerId, $owner->id,
            ['requested_guests' => 0]
        ));
    }

    public function test_booking_event_cannot_attach_to_another_company_draft(): void
    {
        [$company, $foreign, $hall, $owner, $customerId] = $this->fixture();
        $draft = $this->draft($company->id, $hall->id, $customerId, $owner->id);
        DB::table('booking_drafts')->insert($draft);
        $this->expectException(QueryException::class);
        DB::table('booking_draft_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $foreign->id,
            'booking_id' => $draft['id'], 'revision' => 1,
            'action' => 'created', 'after_values' => '{}',
            'actor_id' => $owner->id, 'reason' => 'Create proposal',
        ]);
    }

    public function test_booking_history_cannot_be_updated_or_deleted(): void
    {
        [$company, , $hall, $owner, $customerId] = $this->fixture();
        $draft = $this->draft($company->id, $hall->id, $customerId, $owner->id);
        DB::table('booking_drafts')->insert($draft);
        $eventId = (string) Str::uuid();
        DB::table('booking_draft_events')->insert([
            'id' => $eventId, 'company_id' => $company->id,
            'booking_id' => $draft['id'], 'revision' => 1,
            'action' => 'created', 'after_values' => '{"requestedGuests":200}',
            'actor_id' => $owner->id, 'reason' => 'Create proposal',
        ]);

        $this->expectException(QueryException::class);
        DB::table('booking_draft_events')->where('id', $eventId)->update(['reason' => 'Hidden rewrite']);
    }
}
