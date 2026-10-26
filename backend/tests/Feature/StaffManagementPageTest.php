<?php

namespace Tests\Feature;

use App\Filament\Pages\StaffManagement;
use App\Mail\StaffInvitationMail;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class StaffManagementPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_invites_staff_from_management_page(): void
    {
        $company = Company::create(['name' => 'Staff UI Venue', 'slug' => 'staff-ui-venue']);
        $owner = User::create(['name' => 'Owner', 'email' => 'staff-ui-owner@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Bookable Hall',
            'slug' => 'bookable-hall', 'capacity' => 100]);
        $this->be($owner);
        Mail::fake();

        Livewire::test(StaffManagement::class)
            ->set('companyId', $company->id)
            ->set('email', 'new.staff@example.test')
            ->set('role', 'operations')
            ->set('hallIds', [$hall->id])
            ->set('reason', 'Initial operations staffing')
            ->call('invite')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('staff_invitations', [
            'company_id' => $company->id, 'email' => 'new.staff@example.test',
            'role' => 'operations', 'status' => 'pending',
        ]);
        Mail::assertSent(StaffInvitationMail::class, fn ($mail): bool =>
            $mail->hasTo('new.staff@example.test'));
    }
}
