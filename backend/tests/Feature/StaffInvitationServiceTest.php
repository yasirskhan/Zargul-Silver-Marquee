<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Mail\StaffInvitationMail;
use App\Services\StaffInvitationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffInvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Staff Venue', 'slug' => 'staff-venue']);
        $hall = Hall::create(['company_id' => $company->id,
            'name' => 'Staff Hall', 'slug' => 'staff-hall', 'capacity' => 100]);
        $owner = User::create(['name' => 'Owner',
            'email' => 'staff-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);

        return [$company, $hall, $owner];
    }

    public function test_invitation_acceptance_is_single_use_and_requires_later_verification(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        Mail::fake();
        $service = app(StaffInvitationService::class);
        $invite = $service->invite($owner, $company->id,
            'Invited.Manager@Example.test', 'manager', [$hall->id],
            'Synthetic staff invitation');
        $this->assertSame(1, DB::table('staff_invitations')->count());
        $this->assertSame('pending', DB::table('staff_invitations')->value('status'));
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'staff.invitation_sent')->count());
        $token = null;
        Mail::assertSent(StaffInvitationMail::class, function ($mail) use (&$token): bool {
            $token = $mail->token;
            return true;
        });
        $this->assertIsString($token);

        $accepted = $service->accept($token,
            'Invited Manager', 'a-long-synthetic-password');
        $this->assertSame('manager', $accepted['role']);
        $this->assertFalse($accepted['emailVerified']);
        $this->assertSame('accepted', DB::table('staff_invitations')->value('status'));
        $this->assertNull(DB::table('users')->where('email', 'invited.manager@example.test')
            ->value('email_verified_at'));
        $this->assertSame(1, DB::table('company_memberships')
            ->where('user_id', $accepted['userId'])->count());
        $this->assertSame(1, DB::table('hall_assignments')
            ->where('hall_id', $hall->id)->count());

        try {
            $service->accept($token, 'Replay User', 'another-long-password');
            $this->fail('Invitation token was reusable.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $this->assertTrue(true);
        }

        $service->revoke($owner, $company->id, $invite['id'], 'Owner removed staff access');
        $this->assertFalse((bool) DB::table('company_memberships')
            ->where('user_id', $accepted['userId'])->value('is_active'));
        $this->assertSame('revoked', DB::table('staff_invitations')->value('status'));
    }

    public function test_acceptance_route_creates_unverified_staff_and_rejects_token_replay(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        Mail::fake();
        $invite = app(StaffInvitationService::class)->invite($owner, $company->id,
            'route.accept@example.test', 'reception', [$hall->id], 'Route acceptance test');
        $token = null;
        Mail::assertSent(StaffInvitationMail::class, function ($mail) use (&$token): bool {
            $token = $mail->token;
            return true;
        });
        $this->get('/staff-invitation/'.$token)->assertOk()
            ->assertSee('Accept staff invitation');
        $this->post('/staff-invitation/'.$token, [
            'name' => 'Route Reception', 'password' => 'synthetic-route-password',
            'password_confirmation' => 'synthetic-route-password',
        ])->assertRedirect();
        $this->assertNull(DB::table('users')->where('email', 'route.accept@example.test')
            ->value('email_verified_at'));
        $this->post('/staff-invitation/'.$token, [
            'name' => 'Replay Reception', 'password' => 'synthetic-replay-password',
            'password_confirmation' => 'synthetic-replay-password',
        ])->assertSessionHasErrors('invitation');
    }
    public function test_existing_user_joins_another_company_without_duplicate_account(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $existing = User::create(['name' => 'Existing User',
            'email' => 'existing.staff@example.test', 'password' => 'test-only']);
        $existing->markEmailAsVerified();
        Mail::fake();
        $invite = app(StaffInvitationService::class)->invite($owner, $company->id,
            'existing.staff@example.test', 'operations', [$hall->id],
            'Existing user joins second company');
        $token = null;
        Mail::assertSent(StaffInvitationMail::class, function ($mail) use (&$token): bool {
            $token = $mail->token;
            return true;
        });
        $accepted = app(StaffInvitationService::class)->accept(
            $token, 'Existing User', 'ignored-existing-password');
        $this->assertSame((int) $existing->id, $accepted['userId']);
        $this->assertSame(1, User::where('email', 'existing.staff@example.test')->count());
        $this->assertSame(1, DB::table('company_memberships')->where('company_id', $company->id)
            ->where('user_id', $existing->id)->count());
        $this->assertSame(1, DB::table('hall_assignments')->where('hall_id', $hall->id)->count());
    }
    public function test_manager_cannot_invite_or_read_private_invitations(): void
    {
        [$company, $hall, $owner] = $this->fixture();
        $manager = User::create(['name' => 'Manager',
            'email' => 'staff-manager@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $manager->id, 'role' => 'manager']);
        try {
            app(StaffInvitationService::class)->invite($manager, $company->id,
                'blocked@example.test', 'reception', [$hall->id], 'Manager attempt');
            $this->fail('Manager invited staff.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, DB::table('staff_invitations')->count());
    }
}
