<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_checks_render_and_completed_installer_redirects_to_login():void
    {
        config()->set('installation.operator_token', 'synthetic-installer-token');
        $this->get('/install')
            ->assertOk()
            ->assertSee('Server readiness')
            ->assertSee('PostgreSQL connection')
            ->assertSee('Writable storage')
            ->assertSee('Create one-time Owner link');
        $company=Company::create(['name'=>'Installed Venue','slug'=>'installed-venue']);
        $owner=User::create(['name'=>'Installed Owner','email'=>'installed-owner@example.test','password'=>'test-only']);
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $this->get('/install')->assertRedirect('/admin/login');
        $this->post('/install',[])->assertRedirect('/admin/login');
    }


    public function test_production_environment_can_reach_secure_installer():void
    {
        config()->set('installation.operator_token', str_repeat('p', 32));
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/install')
            ->assertOk()
            ->assertSee('Server readiness')
            ->assertSee('Supported version')
            ->assertDontSee(storage_path('framework'));
    }

    public function test_short_or_disabled_installer_token_is_not_exposed():void
    {
        config()->set('installation.operator_token', 'short-token');
        $this->get('/install')->assertNotFound();
        config()->set('installation.operator_token', str_repeat('x', 32));
        config()->set('installation.enabled', false);
        $this->get('/install')->assertNotFound();
    }

    public function test_invalid_operator_token_is_rejected_before_owner_creation():void
    {
        config()->set('installation.operator_token', 'synthetic-installer-token');
        $this->post('/install',[
            'operator_token'=>'wrong-token','company_name'=>'Should Not Exist',
            'owner_name'=>'No Owner','email'=>'no-owner@example.test',
        ])->assertForbidden();
        $this->assertSame(0,CompanyMembership::where('role','owner')->count());
    }
}
