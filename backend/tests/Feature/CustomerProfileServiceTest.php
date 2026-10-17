<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CustomerProfileService;
use App\Services\TenantAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class CustomerProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'contact-service']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'contact-service-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->be($owner);

        return [$company, $owner];
    }

    public function test_owner_creates_encrypted_contact_idempotently_with_redacted_history(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(CustomerProfileService::class);
        $id = (string) Str::uuid();
        $first = $service->create($owner, $company->id, $id,
            'Sample Customer', 'Guest@Example.Test', '+1 555 123 4567', 'Consented phone intake');
        $this->assertSame(['customerId' => $id, 'revision' => 1], $first);
        $again = $service->create($owner, $company->id, $id,
            'Sample Customer', 'guest@example.test', '+1 555 123 4567', 'Safe identical retry');
        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('customer_profiles')->count());
        $this->assertSame(1, DB::table('customer_profile_events')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'customer_profile.created')->count());

        $raw = DB::table('customer_profiles')->where('id', $id)->first();
        $this->assertSame('Sample Customer', Crypt::decryptString($raw->name_ciphertext));
        $this->assertStringNotContainsString('Sample Customer', $raw->name_ciphertext);
        $this->assertSame('guest@example.test',
            $service->current($owner, $company->id, $id)['email']);
        $audit = json_encode(DB::table('audit_events')->where('entity_id', $id)->first(), JSON_THROW_ON_ERROR);
        $events = json_encode(DB::table('customer_profile_events')->first(), JSON_THROW_ON_ERROR);
        foreach ([$audit, $events] as $history) {
            $this->assertStringNotContainsString('Sample Customer', $history);
            $this->assertStringNotContainsString('guest@example.test', $history);
            $this->assertStringNotContainsString('555 123', $history);
        }
    }

    public function test_updates_use_cas_and_preserve_history_on_stale_retry(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(CustomerProfileService::class);
        $id = (string) Str::uuid();
        $service->create($owner, $company->id, $id,
            'First Contact', null, '+1 555 111 2222', 'Owner input');
        $next = $service->update($owner, $company->id, $id,
            'Revised Contact', 'revised@example.test', null, 1, 'Customer requested corrected details');
        $this->assertSame(2, $next);
        $this->assertSame('Revised Contact', $service->current($owner, $company->id, $id)['name']);
        $this->assertSame(2, DB::table('customer_profile_events')->count());
        $this->assertSame(2, DB::table('audit_events')->count());
        try {
            $service->update($owner, $company->id, $id,
                'Stale Contact', null, null, 1, 'Stale editor attempted write');
            $this->fail('Stale contact overwrote current contact.');
        } catch (ConflictHttpException) {
            $this->assertSame('Revised Contact', $service->current($owner, $company->id, $id)['name']);
            $this->assertSame(2, DB::table('customer_profile_events')->count());
        }
    }

    public function test_foreign_company_and_manager_without_owner_role_cannot_view_or_edit_contact(): void
    {
        [$company, $owner] = $this->fixture();
        $id = (string) Str::uuid();
        $service = app(CustomerProfileService::class);
        $service->create($owner, $company->id, $id,
            'Private Person', null, null, 'Owner entered private profile');
        $foreign = Company::create(['name' => 'Foreign', 'slug' => 'contact-foreign']);
        try {
            $service->current($owner, $foreign->id, $id);
            $this->fail('Another company customer was exposed.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $manager = User::create([
            'name' => 'Manager', 'email' => 'contact-service-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        $this->expectException(AuthorizationException::class);
        $service->current($manager, $company->id, $id);
    }

    public function test_disabled_authenticated_owner_cannot_read_create_or_update_encrypted_contact(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(CustomerProfileService::class);
        $id = (string) Str::uuid();
        $service->create($owner, $company->id, $id,
            'Private Synthetic Contact', 'private@example.test', null, 'Create staff-only contact');
        $original = DB::table('customer_profiles')->where('id', $id)->first();
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $owner->is_active, 'Authenticated object intentionally remains stale.');

        foreach ([
            fn () => $service->current($owner, $company->id, $id),
            fn () => $service->create($owner, $company->id, (string) Str::uuid(),
                'Another Private Contact', null, null, 'Disabled owner creation'),
            fn () => $service->update($owner, $company->id, $id,
                'Leaked Contact Change', null, null, 1, 'Disabled owner update'),
        ] as $action) {
            try {
                $action();
                $this->fail('Disabled account read or changed sensitive contact records.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, DB::table('customer_profiles')->count());
        $this->assertSame($original->name_ciphertext,
            DB::table('customer_profiles')->where('id', $id)->value('name_ciphertext'));
        $this->assertSame(1, DB::table('customer_profile_events')->count());
        $this->assertSame(1, DB::table('audit_events')->where('entity_type', 'customer_profile')->count());
    }

    public function test_revoke_membership_between_initial_permission_and_locked_profile_update_denies_write(): void
    {
        [$company, $owner] = $this->fixture();
        $id = (string) Str::uuid();
        app(CustomerProfileService::class)->create($owner, $company->id, $id,
            'Original Private Contact', null, null, 'Initial owner profile');
        $original = DB::table('customer_profiles')->where('id', $id)->first();

        $access = new class extends TenantAccess {
            public int $checks = 0;

            public function canManageCustomerProfiles(User $user, string $companyId): bool
            {
                $allowed = parent::canManageCustomerProfiles($user, $companyId);
                if (++$this->checks === 1) {
                    DB::table('company_memberships')->where('company_id', $companyId)
                        ->where('user_id', $user->getKey())->update(['is_active' => false]);
                }
                return (bool) $allowed;
            }
        };
        $this->app->instance(TenantAccess::class, $access);
        try {
            app(CustomerProfileService::class)->update($owner, $company->id, $id,
                'Revoked Editor Attempt', null, null, 1, 'Member revoked before update');
            $this->fail('A revoked editor changed the encrypted contact.');
        } catch (AuthorizationException) {
            $this->assertSame(2, $access->checks);
        } finally {
            $this->app->forgetInstance(TenantAccess::class);
        }

        $saved = DB::table('customer_profiles')->where('id', $id)->first();
        $this->assertSame(1, (int) $saved->revision);
        $this->assertSame($original->name_ciphertext, $saved->name_ciphertext);
        $this->assertSame(1, DB::table('customer_profile_events')->count());
        $this->assertSame(1, DB::table('audit_events')->where('entity_type', 'customer_profile')->count());
    }

    public function test_audit_failure_rolls_back_encrypted_profile_and_event(): void
    {
        [$company, $owner] = $this->fixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_customer_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.action = 'customer_profile.created' THEN
        RAISE EXCEPTION 'Injected customer audit failure';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_customer_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_customer_audit_test()');
        try {
            app(CustomerProfileService::class)->create(
                $owner, $company->id, (string) Str::uuid(),
                'Rollback Customer', null, null, 'Fail atomically with audit'
            );
            $this->fail('Audit failure accepted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected customer audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_customer_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_customer_audit_test()');
        }
        $this->assertSame(0, DB::table('customer_profiles')->count());
        $this->assertSame(0, DB::table('customer_profile_events')->count());
    }
}
