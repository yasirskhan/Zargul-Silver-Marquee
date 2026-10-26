<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicSiteMaintenanceHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrolled_company_without_signed_license_receives_maintenance_503(): void
    {
        $companyId = (string) Str::uuid();
        $websiteId = (string) Str::uuid();
        DB::table('companies')->insert([
            'id' => $companyId, 'name' => 'Maintenance Venue',
            'slug' => 'maintenance-venue', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('websites')->insert([
            'id' => $websiteId, 'company_id' => $companyId,
            'name' => 'Maintenance Website', 'created_at' => now(), 'updated_at' => now(),
        ]);
        config()->set('public_site.enabled', true);
        config()->set('public_site.host', 'venue.test');
        config()->set('public_site.company_id', $companyId);
        config()->set('public_site.website_id', $websiteId);
        config()->set('central_control.activation_enabled', true);
        $identity = app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id' => 1, 'company_id' => $companyId,
            'installation_id' => $identity['installation_id'],
            'customer_id' => (string) Str::uuid(),
            'approved_domain' => 'venue.test',
            'status' => 'registered_pending_license',
            'central_registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('http://venue.test/')->assertStatus(503);
    }
}
