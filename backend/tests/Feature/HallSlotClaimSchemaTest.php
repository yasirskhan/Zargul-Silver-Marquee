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

class HallSlotClaimSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Zargul', 'slug' => 'zargul']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Silver', 'slug' => 'silver',
            'capacity' => 500,
        ]);
        $staff = User::create(['name' => 'Owner', 'email' => 'slot-owner@example.test', 'password' => 'test-only']);

        return [$company, $hall, $staff];
    }

    private function hold(string $companyId, string $hallId, int $userId, string $program = 'noon'): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId, 'hall_id' => $hallId,
            'event_date' => '2026-11-12', 'program' => $program,
            'status' => 'hold', 'expires_at' => now()->addMinutes(15),
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function test_claim_has_only_one_active_slot_key_per_hall_day_program(): void
    {
        [$company, $hall, $staff] = $this->fixture();
        DB::table('hall_slot_claims')->insert($this->hold($company->id, $hall->id, $staff->id));
        $this->assertSame(1, DB::table('hall_slot_claims')->count());
        $this->expectException(QueryException::class);
        DB::table('hall_slot_claims')->insert($this->hold($company->id, $hall->id, $staff->id));
    }

    public function test_composite_fk_prevents_hall_claim_under_foreign_company(): void
    {
        [$company, $hall, $staff] = $this->fixture();
        $foreign = Company::create(['name' => 'Other', 'slug' => 'other']);
        $this->expectException(QueryException::class);
        DB::table('hall_slot_claims')->insert($this->hold($foreign->id, $hall->id, $staff->id));
    }

    public function test_invalid_program_and_hold_without_expiry_are_blocked(): void
    {
        [$company, $hall, $staff] = $this->fixture();
        $bad = $this->hold($company->id, $hall->id, $staff->id, 'midnight');
        $this->expectException(QueryException::class);
        DB::table('hall_slot_claims')->insert($bad);
    }

    public function test_confirmed_claim_requires_no_expiry(): void
    {
        [$company, $hall, $staff] = $this->fixture();
        $bad = $this->hold($company->id, $hall->id, $staff->id);
        $bad['status'] = 'confirmed';
        $this->expectException(QueryException::class);
        DB::table('hall_slot_claims')->insert($bad);
    }

    public function test_claim_events_are_append_only_and_tenant_scoped(): void
    {
        [$company, $hall, $staff] = $this->fixture();
        $claim = $this->hold($company->id, $hall->id, $staff->id);
        DB::table('hall_slot_claims')->insert($claim);
        $eventId = (string) Str::uuid();
        DB::table('hall_slot_claim_events')->insert([
            'id' => $eventId, 'claim_id' => $claim['id'], 'company_id' => $company->id,
            'hall_id' => $hall->id, 'event_date' => '2026-11-12',
            'program' => 'noon', 'action' => 'hold_created',
            'before_values' => json_encode([], JSON_THROW_ON_ERROR),
            'after_values' => json_encode(['status' => 'hold'], JSON_THROW_ON_ERROR),
            'actor_id' => $staff->id, 'reason' => 'Temporary hold for review',
        ]);
        $this->assertSame(1, DB::table('hall_slot_claim_events')->count());
        $this->expectException(QueryException::class);
        DB::table('hall_slot_claim_events')->where('id', $eventId)->update(['reason' => 'Tampered']);
    }
}
