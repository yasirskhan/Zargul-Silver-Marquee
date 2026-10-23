<?php

namespace Tests\Feature;

use App\Models\{Company,CompanyMembership,Hall,HallAssignment,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan,DB};
use Tests\TestCase;

/** Test-only/staging bootstrap must never create real stock, money or credentials in Git. */
final class Phase12StagingFixtureCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_NAMES=[
        'VENUE_PHASE12_STAGING_SEED_ALLOWED',
        'PHASE12_STAGING_OWNER_A_PASSWORD',
        'PHASE12_STAGING_OWNER_B_PASSWORD',
        'PHASE12_STAGING_OWNER_C_PASSWORD',
        'PHASE12_STAGING_ADMIN_PASSWORD',
        'PHASE12_STAGING_MANAGER_A_PASSWORD',
        'PHASE12_STAGING_MANAGER_B_PASSWORD'
    ];

    protected function tearDown():void
    {
        foreach(self::ENV_NAMES as $key)putenv($key);
        parent::tearDown();
    }

    public function test_fixture_refuses_default_off_without_any_company_or_auth_records():void
    {
        putenv('VENUE_PHASE12_STAGING_SEED_ALLOWED');
        $this->assertSame(1,Artisan::call('venuedesk:seed-phase12-staging'));
        $this->assertSame(0,Company::query()->count());
        $this->assertSame(0,User::query()->count());
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
    }

    public function test_fixture_creates_two_hall_synthetic_catalog_without_unauthorized_stock_or_cash():void
    {
        putenv('VENUE_PHASE12_STAGING_SEED_ALLOWED=1');
        foreach(array_slice(self::ENV_NAMES,1) as $i=>$key)
            putenv($key.'=UniqueSyntheticSecretNumber'.($i+100).'ZZ');
        $this->assertSame(0,Artisan::call('venuedesk:seed-phase12-staging'));
        $this->assertSame(1,Company::query()->where('slug','phase12-synthetic-acceptance')->count());
        $this->assertSame(2,Hall::query()->count());
        $this->assertSame(6,User::query()->count());
        $this->assertSame(6,CompanyMembership::query()->count());
        $this->assertSame(2,HallAssignment::query()->count());
        $this->assertSame(8,DB::table('hall_inventory_items')->count());
        $this->assertSame(8,DB::table('hall_inventory_item_events')->count());
        $this->assertSame(8,DB::table('audit_events')
            ->where('action','hall_inventory.item_created')->count());
        $this->assertSame(0,DB::table('hall_inventory_positions')->count());
        $this->assertSame(0,DB::table('hall_inventory_movement_events')->count());
        $this->assertSame(0,DB::table('hall_inventory_loan_drafts')->count());
        $this->assertSame(0,DB::table('finance_journals')->count());
        $this->assertSame(0,DB::table('finance_journal_lines')->count());
        $this->assertSame(1,Artisan::call('venuedesk:seed-phase12-staging'));
        $this->assertSame(8,DB::table('hall_inventory_items')->count());
        foreach(['owner-a','owner-b','owner-c','admin','manager-a','manager-b'] as $i=>$key) {
            $actor=User::query()->where('email','phase12-'.$key.'@example.test')->firstOrFail();
            $this->assertTrue($actor->hasVerifiedEmail());
            $this->assertTrue(password_verify(
                'UniqueSyntheticSecretNumber'.($i+100).'ZZ',
                $actor->getAuthPassword()));
        }
        $managerA=User::query()->where('email','phase12-manager-a@example.test')->firstOrFail();
        $managerB=User::query()->where('email','phase12-manager-b@example.test')->firstOrFail();
        $this->assertNotSame(
            $managerA->memberships()->firstOrFail()->hallAssignments()->firstOrFail()->hall_id,
            $managerB->memberships()->firstOrFail()->hallAssignments()->firstOrFail()->hall_id
        );
    }
}
