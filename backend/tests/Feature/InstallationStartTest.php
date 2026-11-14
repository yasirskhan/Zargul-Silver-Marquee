<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallationStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('installation.operator_token', str_repeat('i', 48));
    }

    public function test_browser_start_requires_the_operator_token_and_issues_one_time_owner_link(): void
    {
        $this->get('/install')->assertOk()->assertSee('Create the installation Owner');
        $this->post('/install', [
            'operator_token' => 'wrong', 'company_name' => 'Zargul Silver Marquee',
            'owner_name' => 'Owner', 'email' => 'owner@zargul.test',
        ])->assertForbidden();
        $this->assertSame(0, DB::table('owner_bootstrap_tokens')->count());

        $response = $this->post('/install', [
            'operator_token' => str_repeat('i', 48),
            'company_name' => 'Zargul Silver Marquee',
            'owner_name' => 'Owner', 'email' => 'owner@zargul.test',
        ])->assertRedirect();
        $token = DB::table('owner_bootstrap_tokens')->value('token_hash');
        $this->assertNotNull($token);
        $response->assertRedirectContains('/install/owner/');
    }

    public function test_browser_start_is_not_available_without_operator_configuration(): void
    {
        config()->set('installation.operator_token', '');
        $this->get('/install')->assertNotFound();
    }
}
