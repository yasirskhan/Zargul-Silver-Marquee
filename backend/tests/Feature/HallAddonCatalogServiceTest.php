<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallAddonCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallAddonCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver Venue', 'slug' => 'silver-venue']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Main', 'slug' => 'main',
            'capacity' => 500,
        ]);
        $owner = User::create(['name' => 'Owner', 'email' => 'addons-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->be($owner);

        return [$company, $hall, $owner];
    }

    private function catalog(): array
    {
        return [
            ['code' => 'catering_a', 'name' => 'Guest meal', 'category' => 'catering',
                'chargeUnit' => 'per_guest', 'rateMinor' => 15000, 'enabled' => true],
            ['code' => 'music_dj', 'name' => 'DJ and sound', 'category' => 'music',
                'chargeUnit' => 'flat', 'rateMinor' => 250000, 'enabled' => true],
        ];
    }

    public function test_owner_configures_versioned_catering_and_dj_catalog_with_audit(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallAddonCatalogService::class);
        $this->assertNull($service->current($owner, $company->id, $hall->id));
        $this->assertSame(1, $service->configure($owner, $company->id, $hall->id,
            $this->catalog(), 'PKR', 0, 'Owner approved initial service catalog'));
        $this->assertEquals($this->catalog(), $service->current($owner, $company->id, $hall->id)['items']);

        $second = $this->catalog();
        $second[1]['enabled'] = false;
        $this->assertSame(2, $service->configure($owner, $company->id, $hall->id,
            $second, 'PKR', 1, 'Temporarily disable DJ package'));
        $this->assertSame(2, DB::table('hall_addon_catalog_revisions')->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'hall.addon_catalog_updated')->count());
        $this->assertSame(2, (int) DB::table('halls')->where('id', $hall->id)->value('addon_catalog_revision'));
    }

    public function test_assigned_manager_reads_catalog_but_cannot_modify_it(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallAddonCatalogService::class);
        $service->configure($owner, $company->id, $hall->id,
            $this->catalog(), 'PKR', 0, 'Approved service list');
        $manager = User::create(['name' => 'Manager', 'email' => 'addons-manager@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create(['company_id' => $company->id, 'hall_id' => $hall->id, 'membership_id' => $membership->id]);
        $this->be($manager);
        $this->assertSame(1, $service->current($manager, $company->id, $hall->id)['revision']);
        $this->expectException(AuthorizationException::class);
        $service->configure($manager, $company->id, $hall->id,
            $this->catalog(), 'PKR', 1, 'Manager illegal catalog change');
    }

    public function test_duplicate_codes_and_markup_rejected_without_history(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallAddonCatalogService::class);
        $invalid = $this->catalog();
        $invalid[1]['code'] = $invalid[0]['code'];
        try {
            $service->configure($owner, $company->id, $hall->id,
                $invalid, 'PKR', 0, 'Duplicate code must fail');
            $this->fail('Duplicate code was accepted');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('hall_addon_catalog_revisions')->count());
        }
        $invalid = $this->catalog();
        $invalid[0]['name'] = '<script>unsafe</script>';
        $this->expectException(InvalidArgumentException::class);
        $service->configure($owner, $company->id, $hall->id,
            $invalid, 'PKR', 0, 'Unsafe name must fail');
    }

    public function test_stale_revision_or_cross_company_mutation_is_denied(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallAddonCatalogService::class);
        $service->configure($owner, $company->id, $hall->id,
            $this->catalog(), 'PKR', 0, 'Initial catalog approval');
        try {
            $service->configure($owner, $company->id, $hall->id,
                $this->catalog(), 'PKR', 0, 'Old editor token');
            $this->fail('Stale catalog write accepted');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('hall_addon_catalog_revisions')->count());
        }
        $other = Company::create(['name' => 'Other Venue', 'slug' => 'other-venue']);
        $this->expectException(AuthorizationException::class);
        $service->configure($owner, $other->id, $hall->id,
            $this->catalog(), 'PKR', 1, 'Cross-company change');
    }

    public function test_audit_failure_rolls_back_catalog_pointer_and_history(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_addon_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Injected add-on audit failure';
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_addon_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_addon_audit_test()');
        try {
            app(HallAddonCatalogService::class)->configure($owner, $company->id, $hall->id,
                $this->catalog(), 'PKR', 0, 'Audit catalog mutation atomically');
            $this->fail('Audit failure accepted');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected add-on audit failure', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_addon_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_addon_audit_test()');
        }

        $this->assertSame(0, (int) DB::table('halls')->where('id', $hall->id)->value('addon_catalog_revision'));
        $this->assertSame(0, DB::table('hall_addon_catalog_revisions')->count());
    }
}
