<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $companyId, int $actorId): array
    {
        return [
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'name_ciphertext' => Crypt::encryptString('Example Guest'),
            'email_ciphertext' => Crypt::encryptString('example@example.test'),
            'phone_ciphertext' => Crypt::encryptString('+10000000000'),
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function event(array $customer, int $actorId, ?string $companyId = null): array
    {
        return [
            'id' => (string) Str::uuid(), 'company_id' => $companyId ?? $customer['company_id'],
            'customer_id' => $customer['id'], 'action' => 'created', 'revision' => 1,
            'before_values' => json_encode(null, JSON_THROW_ON_ERROR),
            'after_values' => json_encode(['fields' => ['name', 'email', 'phone']], JSON_THROW_ON_ERROR),
            'actor_id' => $actorId, 'reason' => 'Staff entered customer contact with consent',
        ];
    }

    public function test_opaque_customer_contact_and_redacted_create_event_can_be_stored(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'customer-silver']);
        $user = User::create(['name' => 'Staff', 'email' => 'customer-staff@example.test', 'password' => 'test-only']);
        $customer = $this->customer($company->id, $user->id);
        DB::table('customer_profiles')->insert($customer);
        DB::table('customer_profile_events')->insert($this->event($customer, $user->id));

        $saved = DB::table('customer_profiles')->where('id', $customer['id'])->first();
        $this->assertSame('Example Guest', Crypt::decryptString($saved->name_ciphertext));
        $this->assertStringNotContainsString('Example Guest', $saved->name_ciphertext);
        $this->assertSame(1, (int) $saved->revision);
        $this->assertTrue((bool) $saved->is_active);
        $this->assertSame(1, DB::table('customer_profile_events')->count());
        $this->assertStringNotContainsString('Example Guest',
            (string) DB::table('customer_profile_events')->value('after_values'));
    }

    public function test_cross_company_event_cannot_attach_to_other_company_customer(): void
    {
        $own = Company::create(['name' => 'Silver', 'slug' => 'customer-own']);
        $foreign = Company::create(['name' => 'Other', 'slug' => 'customer-other']);
        $user = User::create(['name' => 'Staff', 'email' => 'customer-other@example.test', 'password' => 'test-only']);
        $customer = $this->customer($own->id, $user->id);
        DB::table('customer_profiles')->insert($customer);

        $this->expectException(QueryException::class);
        DB::table('customer_profile_events')->insert($this->event($customer, $user->id, $foreign->id));
    }

    public function test_customer_history_is_immutable_for_ordinary_db_role(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'customer-history']);
        $user = User::create(['name' => 'Staff', 'email' => 'customer-history@example.test', 'password' => 'test-only']);
        $customer = $this->customer($company->id, $user->id);
        DB::table('customer_profiles')->insert($customer);
        $event = $this->event($customer, $user->id);
        DB::table('customer_profile_events')->insert($event);
        $this->expectException(QueryException::class);
        DB::table('customer_profile_events')->where('id', $event['id'])
            ->update(['reason' => 'Silently changed']);
    }

    public function test_invalid_profile_revision_is_rejected(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'customer-version']);
        $user = User::create(['name' => 'Staff', 'email' => 'customer-version@example.test', 'password' => 'test-only']);
        $customer = $this->customer($company->id, $user->id);
        $customer['revision'] = 0;
        $this->expectException(QueryException::class);
        DB::table('customer_profiles')->insert($customer);
    }
}
