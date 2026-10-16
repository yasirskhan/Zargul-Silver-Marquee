<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\HallSeatingPriceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class HallSeatingPriceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver Venue', 'slug' => 'silver-venue']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Main Hall', 'slug' => 'main',
            'capacity' => 500, 'included_chairs' => 300, 'included_tables' => 30,
        ]);
        $owner = User::create(['name' => 'Owner', 'email' => 'seating-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->be($owner);

        return [$company, $hall, $owner];
    }

    private function config(): array
    {
        return [
            'capacity' => 500, 'includedChairs' => 300, 'includedTables' => 30,
            'extraChairRateMinor' => 2500, 'extraTableRateMinor' => 12500,
        ];
    }

    public function test_owner_versions_capacity_included_seating_and_extra_prices_atomically(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallSeatingPriceService::class);
        $this->assertNull($service->current($owner, $company->id, $hall->id));
        $this->assertSame(1, $service->configure($owner, $company->id, $hall->id,
            $this->config(), 'PKR', 0, 'Approved initial extra seating rates'));
        $this->assertEquals($this->config(), $service->current($owner, $company->id, $hall->id)['config']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'hall.seating_prices_updated')->count());

        $second = $this->config();
        $second['capacity'] = 550;
        $second['includedChairs'] = 350;
        $second['extraChairRateMinor'] = 2700;
        $this->assertSame(2, $service->configure($owner, $company->id, $hall->id,
            $second, 'PKR', 1, 'Owner adjusted hall seating allowance'));
        $this->assertSame(550, (int) DB::table('halls')->where('id', $hall->id)->value('capacity'));
        $this->assertSame(2, (int) DB::table('halls')->where('id', $hall->id)->value('seating_price_revision'));
        $this->assertSame(2, DB::table('hall_seating_price_revisions')->count());
        $this->assertSame(300, (int) DB::table('hall_seating_price_revisions')
            ->where('revision_number', 1)->where('hall_id', $hall->id)->value('included_chairs'));
        $this->assertSame(2, DB::table('audit_events')->count());
    }

    public function test_assigned_manager_can_read_but_cannot_change_prices(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallSeatingPriceService::class);
        $service->configure($owner, $company->id, $hall->id,
            $this->config(), 'PKR', 0, 'Approve seating prices');
        $manager = User::create(['name' => 'Manager', 'email' => 'seating-manager@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        HallAssignment::create([
            'company_id' => $company->id, 'hall_id' => $hall->id,
            'membership_id' => $membership->id,
        ]);
        $this->be($manager);
        $this->assertSame(1, $service->current($manager, $company->id, $hall->id)['revision']);
        $this->expectException(AuthorizationException::class);
        $service->configure($manager, $company->id, $hall->id,
            $this->config(), 'PKR', 1, 'Unauthorized price change');
    }

    public function test_invalid_or_cross_company_seating_is_rejected_without_history(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallSeatingPriceService::class);
        $invalid = $this->config();
        $invalid['includedChairs'] = 700;
        try {
            $service->configure($owner, $company->id, $hall->id,
                $invalid, 'PKR', 0, 'Exceed guest capacity');
            $this->fail('Extra seating above capacity was accepted');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('hall_seating_price_revisions')->count());
        }

        $other = Company::create(['name' => 'Other', 'slug' => 'other']);
        $this->expectException(AuthorizationException::class);
        $service->configure($owner, $other->id, $hall->id,
            $this->config(), 'PKR', 0, 'Foreign company bypass');
    }

    public function test_stale_revision_and_out_of_band_hall_edit_are_rejected(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $service = app(HallSeatingPriceService::class);
        $service->configure($owner, $company->id, $hall->id,
            $this->config(), 'PKR', 0, 'Initial approved config');
        try {
            $service->configure($owner, $company->id, $hall->id,
                $this->config(), 'PKR', 0, 'Stale editor overwrite');
            $this->fail('Stale configuration was accepted');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('hall_seating_price_revisions')->count());
        }
        DB::table('halls')->where('id', $hall->id)->update(['included_chairs' => 301]);
        $this->expectException(ConflictHttpException::class);
        $service->current($owner, $company->id, $hall->id);
    }

    public function test_audit_failure_rolls_back_hall_seating_and_version_pointer(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_seating_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Injected seating audit failure';
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_seating_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_seating_audit_test()');
        try {
            app(HallSeatingPriceService::class)->configure($owner, $company->id, $hall->id,
                $this->config(), 'PKR', 0, 'Audit should commit with rate changes');
            $this->fail('Audit failure accepted');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected seating audit failure', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_seating_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_seating_audit_test()');
        }

        $this->assertSame(500, (int) DB::table('halls')->where('id', $hall->id)->value('capacity'));
        $this->assertSame(0, (int) DB::table('halls')->where('id', $hall->id)->value('seating_price_revision'));
        $this->assertSame(0, DB::table('hall_seating_price_revisions')->count());
    }
}
