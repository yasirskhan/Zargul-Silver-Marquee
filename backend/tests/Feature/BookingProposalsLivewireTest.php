<?php

namespace Tests\Feature;

use App\Filament\Pages\BookingProposals;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BookingProposalsLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver Venue', 'slug' => 'proposal-ui-main']);
        $foreign = Company::create(['name' => 'Other Venue', 'slug' => 'proposal-ui-foreign']);
        $hall = Hall::create([
            'company_id' => $company->id, 'slug' => 'main-hall',
            'name' => 'Hall for Proposals', 'capacity' => 400,
        ]);
        $foreignHall = Hall::create([
            'company_id' => $foreign->id, 'slug' => 'foreign-hall',
            'name' => 'Hidden Private Hall', 'capacity' => 250,
        ]);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'proposal-ui-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $customer = (string) Str::uuid();
        DB::table('customer_profiles')->insert([
            'id' => $customer, 'company_id' => $company->id,
            'created_by' => $owner->id,
            'name_ciphertext' => Crypt::encryptString('Secret Customer Name'),
            'email_ciphertext' => Crypt::encryptString('private@example.test'),
        ]);
        $otherCustomer = (string) Str::uuid();
        DB::table('customer_profiles')->insert([
            'id' => $otherCustomer, 'company_id' => $foreign->id,
            'created_by' => $owner->id,
            'name_ciphertext' => Crypt::encryptString('Foreign Secret Contact'),
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        return [$company, $foreign, $hall, $foreignHall, $owner, $membership, $customer, $otherCustomer];
    }

    private function proposal(
        string $companyId, string $hallId, string $customerId,
        int $actorId, string $date = '2026-12-15', string $status = 'draft',
        string $program = 'evening',
    ): string {
        $id = (string) Str::uuid();
        DB::table('booking_drafts')->insert([
            'id' => $id, 'company_id' => $companyId,
            'hall_id' => $hallId, 'customer_id' => $customerId,
            'event_date' => $date, 'program' => $program,
            'requested_guests' => 180, 'status' => $status,
            'created_by' => $actorId, 'revision' => 1,
        ]);

        return $id;
    }

    public function test_owner_creates_encrypted_customer_and_reviewed_proposal_atomically_without_booking(): void
    {
        [$company, , $hall, , $owner] = $this->fixture();
        // Keep the only required hall configuration consistent with actual
        // service validation; do not bypass live domain business rules.
        $slot = static fn (string $from, string $to): array => [
            'start' => $from, 'end' => $to, 'setupMinutes' => 10,
            'cleaningMinutes' => 10, 'graceMinutes' => 10,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
            'rentalRateMinor' => 10000,
        ];
        app(\App\Services\HallProgramRulesService::class)->configure(
            $owner, $company->id, $hall->id, [
                'morning' => $slot('08:00', '11:00'),
                'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00'),
            ], 'PKR', 0, 'Synthetic configured venue');
        $page = Livewire::test(BookingProposals::class)
            ->set('intakeName', 'Private New Customer')
            ->set('intakeEmail', 'new-private@example.test')
            ->set('intakeHallId', $hall->id)
            ->set('intakeDate', '2027-09-18')
            ->set('intakeProgram', 'evening')
            ->set('intakeGuests', '120')
            ->set('intakeReason', 'Staff confirmed private customer intake')
            ->set('intakeSubmitReview', true)
            ->call('createIntake')->assertHasNoErrors()
            ->assertSet('intakeName', '')
            ->assertSet('intakeEmail', '')
            ->assertDontSee('Private New Customer')
            ->assertDontSee('new-private@example.test');
        $this->assertSame(2, DB::table('customer_profiles')->where('company_id', $company->id)->count());
        $this->assertSame(1, DB::table('booking_drafts')->where('company_id', $company->id)
            ->where('status', 'under_review')->count());
        $newCustomerId = DB::table('booking_drafts')->where('company_id', $company->id)
            ->where('status', 'under_review')->value('customer_id');
        $saved = DB::table('customer_profiles')->where('id', $newCustomerId)->first();
        $this->assertNotNull($saved);
        $this->assertSame('Private New Customer', Crypt::decryptString($saved->name_ciphertext));
        $this->assertSame(2, DB::table('booking_draft_events')->count());
        $this->assertSame(0, DB::table('venue_reservations')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_customer_intake_rolls_back_when_hall_or_guest_validation_fails(): void
    {
        [$company, , $hall] = $this->fixture();
        Livewire::test(BookingProposals::class)
            ->set('intakeName', 'Rollback Private Customer')
            ->set('intakeHallId', $hall->id)
            ->set('intakeDate', '2027-09-18')
            ->set('intakeProgram', 'morning')
            ->set('intakeGuests', '9999')
            ->set('intakeReason', 'Invalid capacity must rollback')
            ->call('createIntake')->assertHasErrors(['intakeReason']);
        $this->assertSame(1, DB::table('customer_profiles')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('booking_drafts')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('customer_profile_events')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }

    public function test_owner_sees_only_company_proposal_metadata_without_contact_or_claims(): void
    {
        [$company, $foreign, $hall, $foreignHall, $owner, , $customer, $otherCustomer] = $this->fixture();
        $this->proposal($company->id, $hall->id, $customer, $owner->id);
        $this->proposal($foreign->id, $foreignHall->id, $otherCustomer, $owner->id);

        $component = Livewire::test(BookingProposals::class)
            ->assertSet('companyId', $company->id)
            ->assertSet('loadedCompanyId', $company->id)
            ->assertSee('Hall for Proposals')
            ->assertDontSee('Hidden Private Hall')
            ->assertSee('NOT confirmed bookings or holds')
            ->assertDontSee('Secret Customer Name')
            ->assertDontSee('private@example.test')
            ->assertDontSee('Foreign Secret Contact');
        $this->assertCount(1, $component->get('entries'));
        $this->assertSame('2026-12-15', $component->get('entries')[0]['eventDate']);
        $this->assertArrayNotHasKey('customerId', $component->get('entries')[0]);
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }


    public function test_stale_owner_session_cannot_read_proposal_screen_after_account_revocation(): void
    {
        [$company, , $hall, , $owner, , $customer] = $this->fixture();
        $this->proposal($company->id, $hall->id, $customer, $owner->id);
        $this->assertTrue(BookingProposals::canAccess());

        // Revoke persisted account without refreshing the authenticated model.
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active);
        $this->assertFalse(BookingProposals::canAccess());
        Livewire::test(BookingProposals::class)->assertForbidden();

        DB::table('users')->where('id', $owner->id)
            ->update(['is_active' => true, 'email_verified_at' => null]);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse(BookingProposals::canAccess());
        Livewire::test(BookingProposals::class)->assertForbidden();

        $this->assertSame(1, DB::table('booking_drafts')->count());
        $this->assertSame(0, DB::table('booking_draft_events')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }

    public function test_reference_lookup_is_exact_company_scoped_and_never_returns_customer_contact(): void
    {
        [$company, $foreign, $hall, $foreignHall, $owner, , $customer, $otherCustomer] = $this->fixture();
        $first = $this->proposal($company->id, $hall->id, $customer, $owner->id, '2026-12-15');
        $second = $this->proposal($company->id, $hall->id, $customer, $owner->id, '2026-12-16');
        $foreignId = $this->proposal($foreign->id, $foreignHall->id, $otherCustomer, $owner->id);

        $component = Livewire::test(BookingProposals::class)
            ->assertSee('Proposal reference (exact UUID)')
            ->assertSee($first)
            ->assertSee($second)
            ->set('referenceSearch', $second)
            ->call('refreshResults')
            ->assertSee($second)
            ->assertDontSee($first)
            ->assertDontSee('Secret Customer Name')
            ->assertDontSee('private@example.test');
        $this->assertCount(1, $component->get('entries'));
        $this->assertSame($second, $component->get('entries')[0]['id']);
        $component->set('referenceSearch', $foreignId)
            ->call('refreshResults')->assertSet('entries', []);
        $component->set('referenceSearch', 'not-a-valid-reference')
            ->call('refreshResults')->assertHasErrors(['referenceSearch']);
        $this->assertSame(3, DB::table('booking_drafts')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_global_search_query_prefilters_the_authorized_company_proposal(): void
    {
        [$company, , $hall, , $owner, , $customer] = $this->fixture();
        $first = $this->proposal($company->id, $hall->id, $customer, $owner->id, '2026-12-15');
        $second = $this->proposal($company->id, $hall->id, $customer, $owner->id, '2026-12-16');
        $component = Livewire::withQueryParams(['company' => $company->id, 'proposal' => $second])
            ->test(BookingProposals::class)
            ->assertSet('companyId', $company->id)
            ->assertSet('referenceSearch', $second)
            ->assertSee($second)
            ->assertDontSee($first)
            ->assertDontSee('private@example.test');
        $this->assertCount(1, $component->get('entries'));
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }

    public function test_owner_filters_same_day_hall_proposals_by_morning_noon_evening_program(): void
    {
        [$company, , $hall, , $owner, , $customer] = $this->fixture();
        $morning = $this->proposal($company->id, $hall->id, $customer,
            $owner->id, '2026-12-15', 'draft', 'morning');
        $evening = $this->proposal($company->id, $hall->id, $customer,
            $owner->id, '2026-12-15', 'draft', 'evening');

        $panel = Livewire::test(BookingProposals::class)
            ->assertSee('Hall program')
            ->set('hallId', $hall->id)
            ->set('fromDate', '2026-12-15')
            ->set('toDate', '2026-12-15')
            ->set('programFilter', 'morning')->call('refreshResults')
            ->assertHasNoErrors()->assertSee($morning)->assertDontSee($evening);
        $this->assertSame([$morning], array_column($panel->get('entries'), 'id'));
        $panel->set('programFilter', 'evening')->call('refreshResults')
            ->assertHasNoErrors()->assertSee($evening)->assertDontSee($morning);
        $this->assertSame([$evening], array_column($panel->get('entries'), 'id'));
        $panel->set('programFilter', 'forged')->call('refreshResults')
            ->assertHasErrors(['programFilter']);
        $this->assertSame(2, DB::table('booking_drafts')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('approval_decisions')->count());
    }

    public function test_calendar_filters_scope_existing_drafts_to_exact_hall_and_day(): void
    {
        [$company, $foreign, $hall, $foreignHall, $owner, , $customer, $otherCustomer] = $this->fixture();
        $target = $this->proposal($company->id, $hall->id, $customer, $owner->id, '2026-12-15');
        $second = $this->proposal($company->id, $hall->id, $customer, $owner->id, '2026-12-16');
        $other = $this->proposal($foreign->id, $foreignHall->id, $otherCustomer, $owner->id, '2026-12-15');

        $component = Livewire::test(BookingProposals::class)
            ->set('hallId', $hall->id)
            ->set('fromDate', '2026-12-15')
            ->set('toDate', '2026-12-15')
            ->call('refreshResults')->assertHasNoErrors()
            ->assertSee($target)
            ->assertDontSee($second)
            ->assertDontSee($other);
        $this->assertSame([$target], array_column($component->get('entries'), 'id'));
        $component->set('hallId', $foreignHall->id)
            ->call('refreshResults')->assertForbidden();
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('approval_decisions')->count());
    }

    public function test_filters_and_pagination_do_not_mutate_proposal_records(): void
    {
        [$company, , $hall, , $owner, , $customer] = $this->fixture();
        for ($i = 0; $i < 27; $i++) {
            $this->proposal($company->id, $hall->id, $customer, $owner->id);
        }
        $component = Livewire::test(BookingProposals::class);
        $this->assertCount(25, $component->get('entries'));
        $component->assertSet('hasMore', true)->call('nextPage')->assertSet('offset', 25);
        $this->assertCount(2, $component->get('entries'));
        $component->call('previousPage')->assertSet('offset', 0)
            ->set('fromDate', '2026-12-16')
            ->call('refreshResults')->assertSet('entries', []);
        $this->assertSame(27, DB::table('booking_drafts')->count());
        $this->assertSame(0, DB::table('booking_draft_events')->count());
    }

    public function test_manager_unverified_owner_and_revoked_membership_are_forbidden(): void
    {
        [$company, , $hall, , $owner, $membership, $customer] = $this->fixture();
        $this->proposal($company->id, $hall->id, $customer, $owner->id);
        $component = Livewire::test(BookingProposals::class);
        $manager = User::create([
            'name' => 'Manager', 'email' => 'proposal-ui-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        Livewire::test(BookingProposals::class)->assertForbidden();
        $this->be($owner);
        $membership->update(['is_active' => false]);
        $component->call('refreshResults')->assertForbidden();
        Livewire::test(BookingProposals::class)->assertForbidden();
        $membership->update(['is_active' => true]);
        $owner->forceFill(['email_verified_at' => null])->save();
        Livewire::test(BookingProposals::class)->assertForbidden();
    }

    public function test_forged_company_hall_and_invalid_filters_are_rejected(): void
    {
        [$company, $foreign, $hall, $foreignHall, $owner, , $customer] = $this->fixture();
        $this->proposal($company->id, $hall->id, $customer, $owner->id);
        Livewire::test(BookingProposals::class)
            ->set('companyId', $foreign->id)->assertForbidden();
        Livewire::test(BookingProposals::class)
            ->set('hallId', $foreignHall->id)
            ->call('refreshResults')->assertForbidden();
        Livewire::test(BookingProposals::class)
            ->set('statusFilter', 'confirmed')
            ->call('refreshResults')->assertHasErrors(['statusFilter']);
        Livewire::test(BookingProposals::class)
            ->set('fromDate', '2026-02-30')
            ->call('refreshResults')->assertHasErrors(['fromDate']);
    }


    public function test_switching_between_owned_companies_clears_old_status_and_reference_filters(): void
    {
        [$company, $other, $hall, $otherHall, $owner, , $customer, $otherCustomer] = $this->fixture();
        CompanyMembership::create([
            'company_id' => $other->id, 'user_id' => $owner->id, 'role' => 'admin',
        ]);

        $first = $this->proposal($company->id, $hall->id, $customer,
            $owner->id, '2026-12-15', 'under_review');
        $second = $this->proposal($other->id, $otherHall->id, $otherCustomer,
            $owner->id, '2026-12-16', 'draft');

        $component = Livewire::test(BookingProposals::class)
            ->set('statusFilter', 'under_review')
            ->set('referenceSearch', $first)
            ->call('refreshResults')
            ->assertSet('loadedCompanyId', $company->id)
            ->assertSee($first)
            ->assertDontSee($second);

        $component->set('companyId', $other->id)
            ->assertSet('loadedCompanyId', $other->id)
            ->assertSet('statusFilter', '')
            ->assertSet('referenceSearch', '')
            ->assertSet('hallId', null)
            ->assertSet('offset', 0)
            ->assertSee($second)
            ->assertDontSee($first)
            ->assertDontSee('Secret Customer Name')
            ->assertDontSee('Foreign Secret Contact');

        $this->assertSame([$second], array_column($component->get('entries'), 'id'));
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_owner_authorized_for_two_companies_can_switch_without_cross_tenant_cache(): void
    {
        [$company, $foreign, $hall, $foreignHall, $owner, , $customer, $foreignCustomer] = $this->fixture();
        CompanyMembership::create([
            'company_id' => $foreign->id, 'user_id' => $owner->id, 'role' => 'admin',
        ]);
        $this->proposal($company->id, $hall->id, $customer, $owner->id);
        $this->proposal($foreign->id, $foreignHall->id, $foreignCustomer, $owner->id);
        $component = Livewire::test(BookingProposals::class)
            ->set('companyId', $foreign->id)
            ->assertSet('loadedCompanyId', $foreign->id)
            ->assertSee('Hidden Private Hall')
            ->assertDontSee('Hall for Proposals');
        $this->assertCount(1, $component->get('entries'));
    }
}
