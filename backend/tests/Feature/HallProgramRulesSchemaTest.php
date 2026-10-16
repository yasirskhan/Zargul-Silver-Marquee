<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hall;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class HallProgramRulesSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function rules(): array
    {
        return [
            'morning' => ['start' => '08:00', 'end' => '11:00'],
            'noon' => ['start' => '12:00', 'end' => '15:00'],
            'evening' => ['start' => '17:00', 'end' => '22:00'],
        ];
    }

    public function test_hall_starts_with_unconfigured_rules_and_has_immutable_version_history(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'silver-programs']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand', 'slug' => 'grand',
            'capacity' => 400,
        ]);
        $this->assertTrue(Schema::hasColumn('halls', 'program_rules_revision'));
        $this->assertSame(0, (int) DB::table('halls')->where('id', $hall->id)->value('program_rules_revision'));
        $actor = User::create(['name' => 'Owner', 'email' => 'programs@example.test', 'password' => 'test-only']);

        $id = (string) Str::uuid();
        DB::table('hall_program_rule_revisions')->insert([
            'id' => $id, 'company_id' => $company->id, 'hall_id' => $hall->id,
            'revision_number' => 1, 'currency_code' => 'PKR',
            'rules' => json_encode($this->rules(), JSON_THROW_ON_ERROR),
            'changed_by' => $actor->id, 'reason' => 'First approved venue schedule',
        ]);
        $stored = DB::table('hall_program_rule_revisions')->where('id', $id)->first();
        $this->assertSame('PKR', $stored->currency_code);
        $this->assertSame('08:00', json_decode($stored->rules, true)['morning']['start']);
        $this->expectException(QueryException::class);
        DB::table('hall_program_rule_revisions')->where('id', $id)->update(['reason' => 'Silently rewritten']);
    }

    public function test_cross_company_hall_history_is_rejected_by_composite_foreign_key(): void
    {
        $a = Company::create(['name' => 'First', 'slug' => 'first-program']);
        $b = Company::create(['name' => 'Second', 'slug' => 'second-program']);
        $hall = Hall::create([
            'company_id' => $a->id, 'name' => 'Hall A', 'slug' => 'hall-a', 'capacity' => 200,
        ]);
        $owner = User::create(['name' => 'Owner', 'email' => 'cross-program@example.test', 'password' => 'test-only']);
        $this->expectException(QueryException::class);
        DB::table('hall_program_rule_revisions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $b->id, 'hall_id' => $hall->id,
            'revision_number' => 1, 'currency_code' => 'PKR',
            'rules' => json_encode($this->rules(), JSON_THROW_ON_ERROR),
            'changed_by' => $owner->id, 'reason' => 'Cross-company should fail',
        ]);
    }

    public function test_revision_rejects_missing_or_extra_programs_in_postgresql(): void
    {
        $company = Company::create(['name' => 'Venue', 'slug' => 'venue-program']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Hall', 'slug' => 'hall', 'capacity' => 200]);
        $actor = User::create(['name' => 'Owner', 'email' => 'invalid-program@example.test', 'password' => 'test-only']);
        $invalid = $this->rules();
        $invalid['extra'] = ['start' => '23:00', 'end' => '23:59'];
        $this->expectException(QueryException::class);
        DB::table('hall_program_rule_revisions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'hall_id' => $hall->id,
            'revision_number' => 1, 'currency_code' => 'PKR',
            'rules' => json_encode($invalid, JSON_THROW_ON_ERROR),
            'changed_by' => $actor->id, 'reason' => 'Invalid fourth session',
        ]);
    }
}
