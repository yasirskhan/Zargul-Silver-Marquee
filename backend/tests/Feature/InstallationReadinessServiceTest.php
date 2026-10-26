<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\InstallationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstallationReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_active_bookable_hall_must_be_configured(): void
    {
        $company = Company::create(['name' => 'Readiness Venue', 'slug' => 'readiness-venue']);
        $owner = User::create(['name' => 'Owner', 'email' => 'readiness@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        $owner->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        foreach ([1, 0] as $index => $revision) {
            DB::table('halls')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company->id,
                'slug' => 'readiness-hall-'.$index, 'name' => 'Hall '.$index,
                'capacity' => 100, 'is_active' => true,
                'program_rules_revision' => $revision,
                'seating_price_revision' => $revision,
                'addon_catalog_revision' => $revision,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->be($owner);
        $result = app(InstallationReadinessService::class)->evaluate($owner, $company->id, [
            'company' => ['legalName' => 'Readiness Venue', 'currency' => 'PKR',
                'timezone' => 'Asia/Karachi'],
            'staff' => ['ownerOnly' => true],
        ]);
        $this->assertFalse($result['ready']);
        $this->assertContains('programs', $result['failures']);
        $this->assertContains('pricing', $result['failures']);
        $this->assertContains('addons', $result['failures']);
    }
}
