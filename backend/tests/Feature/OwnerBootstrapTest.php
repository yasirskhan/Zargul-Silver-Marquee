<?php

namespace Tests\Feature;

use App\Services\OwnerBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnerBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_time_bootstrap_creates_verified_owner_and_cannot_replay(): void
    {
        $service = app(OwnerBootstrapService::class);
        $issued = $service->issue('Bootstrap Venue', 'First Owner', 'first.owner@example.test');
        $this->get('/install/owner/'.$issued['token'])->assertOk()
            ->assertSee('Create the installation Owner');
        $this->post('/install/owner/'.$issued['token'], [
            'password' => 'synthetic-bootstrap-password',
            'password_confirmation' => 'synthetic-bootstrap-password',
        ])->assertRedirect();
        $owner = DB::table('users')->where('email', 'first.owner@example.test')->first();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertSame('owner', DB::table('company_memberships')
            ->where('user_id', $owner->id)->value('role'));
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'installation.owner_bootstrapped')->count());

        $this->post('/install/owner/'.$issued['token'], [
            'password' => 'replay-bootstrap-password',
            'password_confirmation' => 'replay-bootstrap-password',
        ])->assertSessionHasErrors('bootstrap');
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_bootstrap_cannot_be_issued_when_owner_exists(): void
    {
        $service = app(OwnerBootstrapService::class);
        $issued = $service->issue('Bootstrap Venue', 'First Owner', 'first.owner@example.test');
        $service->accept($issued['token'], 'synthetic-bootstrap-password');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $service->issue('Second Venue', 'Second Owner', 'second.owner@example.test');
    }
}
