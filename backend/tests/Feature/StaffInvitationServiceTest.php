<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\StaffInvitationService;
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
        $service = app(StaffInvitationService::class);
        $invite = $service->invite($owner, $company->id,
            'Invited.Manager@Example.test', 'manager', [$hall->id],
            'Synthetic staff invitation');
        $this->assertSame(1, DB::table('staff_invitations')->count());
        $this->assertSame('pending', DB::table('staff_invitations')->value('status'));
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'staff.invitation_sent')->count());

        $accepted = $service->accept($invite['token'],
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
            $service->accept($invite['token'], 'Replay User', 'another-long-password');
            $this->fail('Invitation token was reusable.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $this->assertTrue(true);
        }

        $service->revoke($owner, $company->id, $invite['id'], 'Owner removed staff access');
        $this->assertFalse((bool) DB::table('company_memberships')
            ->where('user_id', $accepted['userId'])->value('is_active'));
        $this->assertSame('revoked', DB::table('staff_invitations')->value('status'));
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
