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
