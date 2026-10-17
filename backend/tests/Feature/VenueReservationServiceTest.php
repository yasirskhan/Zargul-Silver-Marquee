<?php

namespace Tests\Feature;

use App\Filament\Pages\HallCalendar;
use App\Filament\Pages\VenueReservations;
use App\Filament\Pages\EventOperations;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use App\Services\BookingDraftReviewService;
use App\Services\BookingDraftService;
use App\Services\CustomerProfileService;
use App\Services\HallOccupancyCalendarService;
use App\Services\HallProgramRulesService;
use App\Services\HallSlotHoldService;
use App\Services\VenueReservationService;
use App\Services\VenueEventOperationsService;
use App\Services\OperationsPlanningService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Livewire\Livewire;
use Tests\TestCase;

class VenueReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Booking Acceptance Venue',
            'slug' => 'booking-acceptance']);
        $foreign = Company::create(['name' => 'Foreign Booking Venue',
            'slug' => 'foreign-booking-acceptance']);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Test Marquee',
            'slug' => 'test-marquee', 'capacity' => 240]);
        $people = [];
        foreach (['owner', 'manager', 'reception', 'operations'] as $role) {
            $user = User::create(['name' => ucfirst($role),
                'email' => 'reservation-'.$role.'@example.test', 'password' => 'synthetic-only']);
            $user->markEmailAsVerified();
            $member = CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $user->id, 'role' => $role,
            ]);
            if (in_array($role, ['manager', 'reception', 'operations'], true)) {
                HallAssignment::create([
                    'company_id' => $company->id, 'hall_id' => $hall->id,
                    'membership_id' => $member->id,
                ]);
            }
            $people[$role] = $user;
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($people['owner']);
        $program = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 10,
            'cleaningMinutes' => 10, 'graceMinutes' => 10,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
            'rentalRateMinor' => 450000,
        ];
        app(HallProgramRulesService::class)->configure(
            $people['owner'], $company->id, $hall->id, [
                'morning' => $program('08:00', '11:00'),
                'noon' => $program('12:00', '15:00'),
                'evening' => $program('17:00', '22:00'),
            ], 'PKR', 0, 'Approved synthetic internal hall schedule'
        );
        $customer = (string) Str::uuid();
        app(CustomerProfileService::class)->create(
            $people['owner'], $company->id, $customer,
            'Synthetic Reservation Guest', 'hidden-reservation@example.test', null,
            'Private synthetic customer for booking lifecycle acceptance'
        );
        config()->set('booking_controls.confirmation_enabled', true);
        return [$company, $foreign, $hall, $people, $customer];
    }

    private function review(
        Company $company, Hall $hall, User $owner, string $customer,
        string $date = '2027-04-08', string $program = 'evening',
    ): string {
        $id = (string) Str::uuid();
        app(BookingDraftService::class)->create($owner, $company->id, $id,
            $hall->id, $customer, $date, $program, 120,
            'Synthetic reviewed event request');
        app(BookingDraftReviewService::class)->submit($owner, $company->id,
            $id, 1, 'Synthetic internal request ready for staff booking');
        return $id;
    }

    private function confirm(User $actor, Company $company, string $draft): array
    {
        return app(VenueReservationService::class)->confirm(
            $actor, $company->id, $draft, (string) Str::uuid(),
            (string) Str::uuid(), 2, 'Synthetic staff confirmed event booking'
        );
    }

    public function test_staff_intake_availability_glance_tracks_hold_without_creating_booking(): void
    {
        [$company, , $hall, $people] = $this->fixture();
        $this->be($people['manager']);
        $panel = Livewire::test(VenueReservations::class)
            ->set('intakeHallId', $hall->id)
            ->set('intakeDate', '2027-09-12')
            ->set('intakeProgram', 'evening')
            ->assertSee('Selected hall session availability: available');
        $this->assertSame('available', $panel->instance()->intakeAvailability());
        $id = (string) Str::uuid();
        app(HallSlotHoldService::class)->placeHold(
            $people['manager'], $company->id, $hall->id,
            '2027-09-12', 'evening', $id, 20,
            'Synthetic manager private availability check'
        );
        $panel->call('intakeAvailability')->assertSee('Selected hall session availability: hold');
        $this->assertSame('hold', $panel->instance()->intakeAvailability());
        app(HallSlotHoldService::class)->releaseOwnHold(
            $people['manager'], $company->id, $hall->id, $id,
            'Synthetic private hold released after venue check'
        );
        $panel->call('intakeAvailability')->assertSee('Selected hall session availability: available');
        $this->assertSame(0, DB::table('venue_reservations')->count());
        $this->assertSame(0, DB::table('booking_drafts')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        $panel->set('intakeHallId', (string) Str::uuid())->assertForbidden();
    }

    public function test_assigned_staff_reuses_authorized_customer_id_without_decrypting_or_duplicating(): void
    {
        [$company, $foreign, $hall, $people, $customer] = $this->fixture();
        $this->be($people['manager']);
        $before = DB::table('customer_profiles')->where('company_id', $company->id)->count();
        $panel = Livewire::test(VenueReservations::class)
            ->set('intakeExistingCustomerId', $customer)
            ->set('intakeHallId', $hall->id)
            ->set('intakeDate', '2027-08-20')
            ->set('intakeProgram', 'morning')
            ->set('intakeGuests', '95')
            ->set('intakeReason', 'Manager links approved existing customer reference')
            ->call('createStaffDraft')->assertHasNoErrors()
            ->assertSet('intakeExistingCustomerId', '')
            ->assertDontSee('hidden-reservation@example.test');
        $draft = $panel->get('lastIntakeDraftId');
        $this->assertSame($customer, DB::table('booking_drafts')->where('id', $draft)->value('customer_id'));
        $this->assertSame($before, DB::table('customer_profiles')->where('company_id', $company->id)->count());
        $this->assertSame('draft', DB::table('booking_drafts')->where('id', $draft)->value('status'));
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_staff_cannot_attach_nonexistent_or_other_company_customer_to_booking_request(): void
    {
        [$company, , $hall, $people] = $this->fixture();
        $this->be($people['reception']);
        $forged = (string) Str::uuid();
        Livewire::test(VenueReservations::class)
            ->set('intakeExistingCustomerId', $forged)
            ->set('intakeHallId', $hall->id)
            ->set('intakeDate', '2027-08-21')
            ->set('intakeProgram', 'evening')
            ->set('intakeGuests', '75')
            ->set('intakeReason', 'Reject forged customer linkage')
            ->call('createStaffDraft')->assertForbidden();
        $this->assertSame(0, DB::table('booking_drafts')->count());
        $this->assertSame(0, DB::table('venue_reservations')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_assigned_manager_intake_then_owner_review_then_confirmed_booking(): void
    {
        [$company, , $hall, $people] = $this->fixture();
        $this->be($people['manager']);
        $panel = Livewire::test(VenueReservations::class)
            ->set('intakeName', 'Private Assisted Booking')
            ->set('intakeEmail', 'assisted-private@example.test')
            ->set('intakeHallId', $hall->id)
            ->set('intakeDate', '2027-08-15')
            ->set('intakeProgram', 'noon')
            ->set('intakeGuests', '120')
            ->set('intakeReason', 'Manager recorded a verified private customer request')
            ->call('createStaffDraft')->assertHasNoErrors()
            ->assertSet('intakeName', '')
            ->assertSet('intakeEmail', '')
            ->assertDontSee('assisted-private@example.test');
        $draft = $panel->get('lastIntakeDraftId');
        $this->assertTrue(Str::isUuid($draft));
        $this->assertSame('draft', DB::table('booking_drafts')->where('id', $draft)->value('status'));
        $this->assertSame(0, DB::table('venue_reservations')->count());
        $this->be($people['owner']);
        app(BookingDraftReviewService::class)->submit($people['owner'], $company->id,
            $draft, 1, 'Owner reviewed synthetic staff-proposed date and hall');
        $this->be($people['manager']);
        Livewire::test(VenueReservations::class)
            ->set('draftId', $draft)->set('reason', 'Manager confirms owner-reviewed booking')
            ->call('confirmSelected')->assertHasNoErrors();
        $this->assertSame(1, DB::table('venue_reservations')->where('source_draft_id', $draft)->count());
        $this->assertSame(1, DB::table('hall_slot_claims')->where('status', 'confirmed')->count());
        Livewire::test(HallCalendar::class)->set('anchorDate', '2027-08-15')
            ->set('viewMode', 'day')->assertSet('projection.days.0.programs.noon.status', 'confirmed');
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_reception_invalid_capacity_rolls_back_new_customer_and_draft(): void
    {
        [$company, , $hall, $people] = $this->fixture();
        $this->be($people['reception']);
        $count = DB::table('customer_profiles')->where('company_id', $company->id)->count();
        Livewire::test(VenueReservations::class)
            ->set('intakeName', 'Bad Capacity Client')
            ->set('intakeHallId', $hall->id)
            ->set('intakeDate', '2027-08-17')
            ->set('intakeProgram', 'evening')
            ->set('intakeGuests', '99999')
            ->set('intakeReason', 'Reject beyond permitted hall capacity')
            ->call('createStaffDraft')->assertHasErrors(['intakeReason']);
        $this->assertSame($count, DB::table('customer_profiles')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('booking_drafts')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_manager_panel_places_and_converts_own_hold_to_exclusive_confirmation(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->be($people['manager']);
        $page = Livewire::test(VenueReservations::class)
            ->set('draftId', $draft)
            ->set('holdDuration', '15')
            ->set('reason', 'Owner approved temporary hall hold')
            ->call('holdSelected')->assertHasNoErrors()
            ->assertSee('Own temporary hold expires')
            ->assertSee('Release my hold');
        $id = $page->get('claimRequestKey');
        $this->assertSame('hold', DB::table('hall_slot_claims')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('venue_reservations')->count());
        Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2027-04-08')->set('viewMode', 'day')
            ->assertSet('projection.days.0.programs.evening.status', 'hold');
        $page->set('reason', 'Convert authorized held program to reservation')
            ->call('confirmSelected')->assertHasNoErrors()
            ->assertSet('currentHold', null);
        $this->assertSame('confirmed', DB::table('hall_slot_claims')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('venue_reservations')->count());
        $this->assertSame(2, DB::table('hall_slot_claim_events')->where('claim_id', $id)->count());
    }

    public function test_temporary_hold_release_and_foreign_staff_refusal_preserve_audit(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->be($people['manager']);
        $page = Livewire::test(VenueReservations::class)
            ->set('draftId', $draft)
            ->set('reason', 'Manager places brief staff hold')
            ->call('holdSelected')->assertHasNoErrors();
        $id = $page->get('claimRequestKey');
        $this->be($people['reception']);
        $this->expectException(AuthorizationException::class);
        app(HallSlotHoldService::class)->releaseOwnHold($people['reception'],
            $company->id, $hall->id, $id, 'Cannot release another operator hold');
    }

    public function test_manager_can_release_own_hold_after_owner_withdraws_proposal(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->be($people['manager']);
        $panel = Livewire::test(VenueReservations::class)
            ->set('draftId', $draft)
            ->set('reason', 'Manager holds proposed event while deciding')
            ->call('holdSelected')->assertHasNoErrors();
        $claim = $panel->get('claimRequestKey');
        $this->be($people['owner']);
        app(BookingDraftReviewService::class)->withdraw(
            $people['owner'], $company->id, $draft, 2,
            'Customer requested cancellation before confirmation');
        $this->be($people['manager']);
        $panel->set('reason', 'Release obsolete hold after proposal withdrawal')
            ->call('releaseSelectedHold')->assertHasNoErrors()
            ->assertSet('currentHold', null);
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(2, DB::table('hall_slot_claim_events')
            ->where('claim_id', $claim)->count());
        $this->assertSame(0, DB::table('venue_reservations')->count());
    }

    public function test_temporary_hold_release_exposes_calendar_availability_again(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->be($people['manager']);
        $page = Livewire::test(VenueReservations::class)
            ->set('draftId', $draft)
            ->set('reason', 'Private pending customer scheduling')
            ->call('holdSelected')->assertHasNoErrors();
        $page->set('reason', 'Client postponed private hold')
            ->call('releaseSelectedHold')->assertHasNoErrors()
            ->assertSet('currentHold', null);
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(2, DB::table('hall_slot_claim_events')->count());
        Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2027-04-08')->set('viewMode', 'day')
            ->assertSet('projection.days.0.programs.evening.status', 'available');
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_manager_confirms_reviewed_reservation_and_calendar_shows_exclusive_slot(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->be($people['manager']);
        $service = app(VenueReservationService::class);
        $reservationId = (string) Str::uuid();
        $claimId = (string) Str::uuid();
        $result = $service->confirm($people['manager'], $company->id, $draft,
            $reservationId, $claimId, 2, 'Manager booked agreed evening session');
        $this->assertSame('confirmed', $result['status']);
        $this->assertSame($reservationId, $result['id']);
        $this->assertSame($result, $service->confirm($people['manager'], $company->id,
            $draft, $reservationId, $claimId, 2, 'Manager booked agreed evening session'));
        $calendar = app(HallOccupancyCalendarService::class)->forHall(
            $people['manager'], $company->id, $hall->id, '2027-04-08', '2027-04-08'
        );
        $this->assertSame('confirmed', $calendar['days'][0]['programs']['evening']['status']);
        $this->assertSame('available', $calendar['days'][0]['programs']['morning']['status']);
        $this->assertSame([$reservationId], array_column($service->list(
            $people['manager'], $company->id), 'id'));
        $this->assertSame('booked', DB::table('booking_drafts')
            ->where('id', $draft)->value('status'));
        $this->assertSame(3, DB::table('booking_drafts')
            ->where('id', $draft)->value('revision'));
        $this->assertSame(1, DB::table('booking_draft_events')
            ->where('booking_id', $draft)->where('action', 'booked')->count());
        $this->assertSame(1, DB::table('venue_reservation_events')->count());
        $this->assertSame(1, DB::table('hall_slot_claims')->where('status', 'confirmed')->count());
        $this->assertSame(1, DB::table('hall_slot_claim_events')->where('action', 'confirmed')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertStringNotContainsString('hidden-reservation@example.test',
            json_encode($service->list($people['manager'], $company->id), JSON_THROW_ON_ERROR));
    }

    public function test_reschedule_conflict_rollback_and_cancellation_release_without_erasing_history(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $a = $this->review($company, $hall, $people['owner'], $customer);
        $b = $this->review($company, $hall, $people['owner'], $customer,
            '2027-04-09', 'morning');
        $this->be($people['manager']);
        $first = $this->confirm($people['manager'], $company, $a);
        $second = $this->confirm($people['manager'], $company, $b);
        $service = app(VenueReservationService::class);
        try {
            $service->reschedule($people['manager'], $company->id, $first['id'],
                '2027-04-09', 'morning', (string) Str::uuid(), 1,
                'Attempt booking into occupied hall slot');
            $this->fail('Double booking was permitted.');
        } catch (ConflictHttpException) {
            $this->assertSame(2, DB::table('hall_slot_claims')->where('status', 'confirmed')->count());
            $this->assertSame(1, DB::table('venue_reservations')
                ->where('id', $first['id'])->value('revision'));
        }
        $moved = $service->reschedule($people['manager'], $company->id, $first['id'],
            '2027-04-10', 'noon', (string) Str::uuid(), 1,
            'Customer requested alternative noon session');
        $this->assertSame(2, $moved['revision']);
        $this->assertSame('2027-04-10', $moved['date']);
        $this->assertSame(2, DB::table('hall_slot_claims')->count());
        $this->assertSame(1, DB::table('venue_reservation_events')->where('action', 'rescheduled')->count());
        $cancel = $service->cancel($people['manager'], $company->id,
            $first['id'], 2, 'Customer cancelled the event with audit retained');
        $this->assertSame('cancelled', $cancel['status']);
        $this->assertSame(3, $cancel['revision']);
        $this->assertSame(1, DB::table('hall_slot_claims')->count());
        $this->assertSame(3, DB::table('venue_reservation_events')
            ->where('reservation_id', $first['id'])->count());
        $this->assertSame(2, DB::table('hall_slot_claim_events')
            ->where('action', 'cancelled')->count());
        $this->assertSame('confirmed', DB::table('venue_reservations')
            ->where('id', $second['id'])->value('status'));
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_manager_cross_hall_transfer_uses_exclusive_target_claim_and_denies_unassigned_hall(): void
    {
        [$company, , $source, $people, $customer] = $this->fixture();
        $target = Hall::create(['company_id' => $company->id,
            'name' => 'Second permitted hall', 'slug' => 'second-permitted',
            'capacity' => 180]);
        $unassigned = Hall::create(['company_id' => $company->id,
            'name' => 'Unassigned hall', 'slug' => 'unassigned-transfer',
            'capacity' => 200]);
        $rules = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 10,
            'cleaningMinutes' => 10, 'graceMinutes' => 10,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
            'rentalRateMinor' => 500000,
        ];
        foreach ([$target, $unassigned] as $hall) {
            app(HallProgramRulesService::class)->configure(
                $people['owner'], $company->id, $hall->id, [
                    'morning' => $rules('08:00', '11:00'),
                    'noon' => $rules('12:00', '15:00'),
                    'evening' => $rules('17:00', '22:00'),
                ], 'PKR', 0, 'Synthetic destination hall rules'
            );
        }
        $managerMembership = CompanyMembership::query()->where('company_id', $company->id)
            ->where('user_id', $people['manager']->id)->firstOrFail();
        HallAssignment::create([
            'company_id' => $company->id, 'hall_id' => $target->id,
            'membership_id' => $managerMembership->id,
        ]);
        $draft = $this->review($company, $source, $people['owner'], $customer);
        $this->be($people['manager']);
        $reservation = $this->confirm($people['manager'], $company, $draft);
        $service = app(VenueReservationService::class);
        try {
            $service->reschedule($people['manager'], $company->id, $reservation['id'],
                '2027-04-11', 'noon', (string) Str::uuid(), 1,
                'Attempt to move into a hall not assigned to this manager', $unassigned->id);
            $this->fail('Unassigned hall transfer was allowed.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('hall_slot_claims')->count());
        }

        $moved = $service->reschedule($people['manager'], $company->id, $reservation['id'],
            '2027-04-11', 'noon', (string) Str::uuid(), 1,
            'Manager arranged an authorized alternative hall and program', $target->id);
        $this->assertSame($target->id, $moved['hallId']);
        $this->assertSame(2, $moved['revision']);
        $this->assertSame($target->id, DB::table('hall_slot_claims')
            ->where('status', 'confirmed')->value('hall_id'));
        $sourceDay = app(HallOccupancyCalendarService::class)->forHall(
            $people['manager'], $company->id, $source->id, '2027-04-08', '2027-04-08'
        );
        $targetDay = app(HallOccupancyCalendarService::class)->forHall(
            $people['manager'], $company->id, $target->id, '2027-04-11', '2027-04-11'
        );
        $this->assertSame('available', $sourceDay['days'][0]['programs']['evening']['status']);
        $this->assertSame('confirmed', $targetDay['days'][0]['programs']['noon']['status']);
        $this->assertSame(2, DB::table('venue_reservation_events')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_own_active_hold_converts_to_confirmation_and_other_staff_hold_blocks(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->be($people['manager']);
        $holdId = (string) Str::uuid();
        app(HallSlotHoldService::class)->placeHold(
            $people['manager'], $company->id, $hall->id,
            '2027-04-08', 'evening', $holdId, 20, 'Temporary manager hold for guest'
        );
        $created = app(VenueReservationService::class)->confirm(
            $people['manager'], $company->id, $draft,
            (string) Str::uuid(), $holdId, 2, 'Convert approved temporary hold');
        $this->assertSame($holdId, $created['claimId']);
        $this->assertNull(DB::table('hall_slot_claims')->where('id', $holdId)->value('expires_at'));
        $this->assertSame(1, DB::table('hall_slot_claims')->count());
        $this->assertSame(2, DB::table('hall_slot_claim_events')
            ->where('claim_id', $holdId)->count());
    }

    public function test_release_gate_role_and_tenant_denial_keep_booking_state_unchanged(): void
    {
        [$company, $foreign, $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $service = app(VenueReservationService::class);
        config()->set('booking_controls.confirmation_enabled', false);
        try {
            $this->confirm($people['owner'], $company, $draft);
            $this->fail('Release gate was bypassed.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::table('venue_reservations')->count());
        }
        config()->set('booking_controls.confirmation_enabled', true);
        $this->be($people['operations']);
        try {
            $this->confirm($people['operations'], $company, $draft);
            $this->fail('Operations staff confirmed a booking.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($people['owner']);
        try {
            $service->confirm($people['owner'], $foreign->id, $draft,
                (string) Str::uuid(), (string) Str::uuid(), 2, 'Foreign slot attempt');
            $this->fail('Foreign company proposal was bookable.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, DB::table('venue_reservations')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }

    public function test_owner_looks_up_two_customer_reservations_including_cancelled_event(): void
    {
        [$company, $foreign, $hall, $people, $customer] = $this->fixture();
        $firstDraft = $this->review($company, $hall, $people['owner'], $customer);
        $secondDraft = $this->review($company, $hall, $people['owner'], $customer,
            '2027-04-09', 'noon');
        $service = app(VenueReservationService::class);
        $first = $this->confirm($people['owner'], $company, $firstDraft);
        $second = $this->confirm($people['owner'], $company, $secondDraft);
        $service->cancel($people['owner'], $company->id, $first['id'], 1,
            'Historical customer cancellation retained');
        $ownerPanel = Livewire::test(VenueReservations::class)
            ->assertSee('Customer booking history')
            ->call('showCustomerHistory', $customer)
            ->assertSee('Customer booking history (internal reference only)')
            ->assertSee($first['id'])->assertSee($second['id'])
            ->assertSee('Cancelled')
            ->assertDontSee('hidden-reservation@example.test');
        $this->assertSame($customer, $ownerPanel->get('selectedCustomerId'));
        $this->assertCount(2, $ownerPanel->get('customerReservations'));
        $ownerPanel->call('clearCustomerHistory')
            ->assertSet('selectedCustomerId', '')
            ->assertSet('customerReservations', []);
        $this->expectException(AuthorizationException::class);
        $service->customerHistory($people['owner'], $foreign->id, $customer);
    }

    public function test_manager_cannot_open_owner_customer_history(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->confirm($people['owner'], $company, $draft);
        $this->be($people['manager']);
        Livewire::test(VenueReservations::class)
            ->assertDontSee('Customer booking history');
        Livewire::test(VenueReservations::class)
            ->call('showCustomerHistory', $customer)->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(VenueReservationService::class)->customerHistory(
            $people['manager'], $company->id, $customer);
    }

    public function test_append_only_reservation_history_rejects_mutation(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $result = $this->confirm($people['owner'], $company, $draft);
        $event = DB::table('venue_reservation_events')->where('reservation_id', $result['id'])->first();
        $this->expectException(QueryException::class);
        DB::table('venue_reservation_events')->where('id', $event->id)
            ->update(['reason' => 'Attempted silent historical rewrite']);
    }

    public function test_staff_filament_journey_confirms_reschedules_cancels_and_refreshes_calendar(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->be($people['manager']);
        $panel = Livewire::test(VenueReservations::class)
            ->assertSet('companyId', $company->id)
            ->assertSee('Confirm hall/date/program reservation')
            ->set('draftId', $draft)
            ->set('reason', 'Manager confirmed internal reservation after review')
            ->call('confirmSelected')->assertHasNoErrors()
            ->assertSee('Confirmed')
            ->assertDontSee('hidden-reservation@example.test');
        $booking = DB::table('venue_reservations')->where('source_draft_id', $draft)->first();
        $this->assertNotNull($booking);
        $this->assertSame('booked', DB::table('booking_drafts')->where('id', $draft)->value('status'));
        $this->assertSame($booking->id, $panel->get('selectedReservationId'));

        Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2027-04-08')->set('viewMode', 'day')
            ->assertSet('projection.days.0.programs.evening.status', 'confirmed');

        $panel->set('newDate', '2027-04-10')->set('newProgram', 'morning')
            ->set('reason', 'Synthetic date and program moved by manager')
            ->call('rescheduleSelected')->assertHasNoErrors()
            ->assertSee('2027-04-10');
        Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2027-04-10')->set('viewMode', 'day')
            ->assertSet('projection.days.0.programs.morning.status', 'confirmed');
        $panel->set('reason', 'Synthetic customer cancellation confirmed by manager')
            ->call('cancelSelected')->assertHasNoErrors()
            ->assertSee('Cancelled');
        Livewire::test(HallCalendar::class)
            ->set('anchorDate', '2027-04-10')->set('viewMode', 'day')
            ->assertSet('projection.days.0.programs.morning.status', 'available');
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(3, DB::table('venue_reservation_events')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());

        $this->be($people['reception']);
        Livewire::test(VenueReservations::class)->assertSee('Cancelled');
        $this->be($people['operations']);
        Livewire::test(VenueReservations::class)->assertForbidden();
    }

    public function test_booking_panel_rejects_foreign_company_and_release_gate_prevents_action(): void
    {
        [$company, $foreign, $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->be($people['manager']);
        Livewire::withQueryParams(['company' => $foreign->id])
            ->test(VenueReservations::class)->assertForbidden();

        config()->set('booking_controls.confirmation_enabled', false);
        Livewire::withQueryParams(['company' => $company->id])
            ->test(VenueReservations::class)
            ->assertSee('Live reservation confirmation is disabled')
            ->set('draftId', $draft)
            ->set('reason', 'Cannot bypass release gate')
            ->call('confirmSelected')->assertForbidden();
        $this->assertSame(0, DB::table('venue_reservations')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }
    public function test_actual_event_start_and_finish_record_grace_overrun_without_booking_or_money_mutation(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer,
            '2027-04-08', 'morning');
        $this->be($people['manager']);
        $booking = $this->confirm($people['manager'], $company, $draft);
        config()->set('event_controls.recording_enabled', true);
        $this->be($people['operations']);
        $operations = app(VenueEventOperationsService::class);
        $id = (string) Str::uuid();
        $started = $operations->start($people['operations'], $company->id,
            $booking['id'], $id, '08:05', 'Actual stage crew check in');
        $this->assertSame('in_progress', $started['status']);
        $this->assertSame(1, $started['revision']);
        try {
            $operations->start($people['operations'], $company->id,
                $booking['id'], (string) Str::uuid(), '08:07', 'Duplicate event start');
            $this->fail('Duplicate actual event start was permitted.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('venue_event_operations')->count());
        }
        $completed = $operations->finish($people['operations'], $company->id,
            $booking['id'], 1, '11:45', 'Recorded actual late finish for audit');
        $this->assertSame('completed', $completed['status']);
        $this->assertSame(2, $completed['revision']);
        $this->assertSame('08:05', $completed['start']);
        $this->assertSame('11:45', $completed['end']);
        $this->assertSame(45, $completed['overtime']['overtimeMinutes']);
        $this->assertSame(35, $completed['overtime']['chargeableMinutes']);
        $this->assertSame(2, $completed['overtime']['startedIntervals']);
        $this->assertTrue($completed['overtime']['conflictsWithTurnover']);
        $this->assertFalse($completed['overtime']['extensionAuthorized']);
        $this->assertSame('internal_nonbinding', $completed['overtime']['status']);
        $this->assertSame(2, DB::table('venue_event_operation_events')->count());
        $this->assertSame(2, DB::table('audit_events')
            ->where('entity_type', 'venue_event_operation')->count());
        $this->assertSame(1, DB::table('hall_slot_claims')
            ->where('id', $booking['claimId'])->where('status', 'confirmed')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('venue_invoice_payments')->count());
        $this->be($people['manager']);
        foreach ([
            fn () => app(VenueReservationService::class)->reschedule(
                $people['manager'], $company->id, $booking['id'],
                '2027-04-09', 'noon', (string) Str::uuid(), 1,
                'Cannot rewrite observed event hall after check-in'),
            fn () => app(VenueReservationService::class)->cancel(
                $people['manager'], $company->id, $booking['id'], 1,
                'Cannot silently cancel event after check-in'),
        ] as $action) {
            try {
                $action();
                $this->fail('Already-started event was mutated.');
            } catch (ConflictHttpException) {
                $this->assertSame('confirmed', DB::table('venue_reservations')
                    ->where('id', $booking['id'])->value('status'));
            }
        }
        $this->expectException(QueryException::class);
        DB::table('venue_event_operation_events')->where('operation_id', $id)
            ->update(['reason' => 'Tampered with immutable observation']);
    }

    public function test_actual_event_recording_requires_gate_current_hall_assignment_and_valid_time(): void
    {
        [$company, $foreign, $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer);
        $this->be($people['manager']);
        $booking = $this->confirm($people['manager'], $company, $draft);
        $service = app(VenueEventOperationsService::class);
        try {
            $service->start($people['manager'], $company->id, $booking['id'],
                (string) Str::uuid(), '17:10', 'Recording disabled by policy');
            $this->fail('Operator gate was ignored.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::table('venue_event_operations')->count());
        }
        config()->set('event_controls.recording_enabled', true);
        try {
            $service->start($people['manager'], $foreign->id, $booking['id'],
                (string) Str::uuid(), '17:10', 'Foreign company not allowed');
            $this->fail('Other company booked event was accessible.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('venue_event_operations')->count());
        }
        try {
            $service->start($people['manager'], $company->id, $booking['id'],
                (string) Str::uuid(), '25:10', 'Invalid observed start clock');
            $this->fail('Invalid local clock accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('venue_event_operations')->count());
        }
        $started = $service->start($people['manager'], $company->id, $booking['id'],
            (string) Str::uuid(), '17:05', 'Manager observed actual evening start');
        try {
            $service->finish($people['manager'], $company->id, $booking['id'],
                1, '16:00', 'Invalid observed finish before start');
            $this->fail('Backwards event clock accepted.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('venue_event_operation_events')->count());
        }
        HallAssignment::query()->where('hall_id', $hall->id)
            ->whereHas('membership', fn ($q) => $q->where('user_id', $people['manager']->id))
            ->delete();
        $this->expectException(AuthorizationException::class);
        $service->finish($people['manager'], $company->id, $booking['id'],
            $started['revision'], '22:10', 'Revoked hall operator not authorized');
    }

    public function test_operations_role_can_check_in_and_complete_confirmed_event_from_livewire(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer, '2027-04-08', 'morning');
        $this->be($people['manager']);
        $booking = $this->confirm($people['manager'], $company, $draft);
        config()->set('event_controls.recording_enabled', true);
        $this->be($people['operations']);
        $panel = Livewire::withQueryParams(['company' => $company->id])
            ->test(EventOperations::class)
            ->assertSee('Record or review event')
            ->assertSee('Test Marquee')
            ->assertDontSee('hidden-reservation@example.test')
            ->call('selectReservation', $booking['id'])
            ->set('startClock', '08:02')
            ->set('reason', 'Staff observed hall guest arrival')
            ->call('startEvent')->assertHasNoErrors()
            ->assertSee('in progress')
            ->set('endClock', '11:10')
            ->set('reason', 'Staff observed end of morning program')
            ->call('finishEvent')->assertHasNoErrors()
            ->assertSee('Event completed and sealed');
        $this->assertSame(2, DB::table('venue_event_operation_events')->count());
        $this->assertSame(1, DB::table('hall_slot_claims')
            ->where('id', $booking['claimId'])->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_reviewed_pre_event_task_is_recorded_once_as_actual_service_fulfilment(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer, '2027-04-08', 'morning');
        $planning = app(OperationsPlanningService::class);
        $reviewed = (string) Str::uuid();
        $unreviewed = (string) Str::uuid();
        $planning->create($people['owner'], $company->id, $reviewed,
            $draft, 'Set up extra entrance lighting', 'Private approved event setup task');
        $planning->setReviewed($people['owner'], $company->id, $reviewed, 1, true,
            'Owner authorized stage setup plan only');
        $planning->create($people['owner'], $company->id, $unreviewed,
            $draft, 'Unreviewed extra seating', 'Requires owner review before performing');

        $this->be($people['manager']);
        $booking = $this->confirm($people['manager'], $company, $draft);
        config()->set('event_controls.recording_enabled', true);
        $this->be($people['operations']);
        $service = app(VenueEventOperationsService::class);
        $before = $service->checklist($people['operations'], $company->id, $booking['id']);
        $this->assertCount(2, $before);
        $this->assertSame([false, false], array_column($before, 'fulfilled'));

        $service->start($people['operations'], $company->id, $booking['id'],
            (string) Str::uuid(), '08:06', 'Arrival observed by service team');
        $key = (string) Str::uuid();
        $done = $service->fulfillTask($people['operations'], $company->id,
            $booking['id'], $reviewed, $key, 'Witnessed installed entrance lights');
        $this->assertSame('fulfilled', $done['status']);
        $this->assertSame($done, $service->fulfillTask($people['operations'], $company->id,
            $booking['id'], $reviewed, $key, 'Witnessed installed entrance lights'));
        $after = $service->checklist($people['operations'], $company->id, $booking['id']);
        $this->assertCount(1, array_filter($after, fn (array $task) => $task['fulfilled']));
        try {
            $service->fulfillTask($people['operations'], $company->id,
                $booking['id'], $unreviewed, (string) Str::uuid(),
                'Unreviewed service must not be marked performed');
            $this->fail('Unreviewed task was recorded as actual service.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('venue_event_task_fulfillments')->count());
        }

        $panel = Livewire::withQueryParams(['company' => $company->id])
            ->test(EventOperations::class)
            ->call('selectReservation', $booking['id'])
            ->assertSee('Set up extra entrance lighting')
            ->assertSee('performed')
            ->assertSee('Unreviewed extra seating')
            ->assertDontSee('hidden-reservation@example.test');
        $this->assertCount(2, $panel->get('checklist'));
        $service->finish($people['operations'], $company->id, $booking['id'],
            1, '11:09', 'Observed all guests departed at morning close');
        try {
            $service->fulfillTask($people['operations'], $company->id,
                $booking['id'], $reviewed, (string) Str::uuid(),
                'Too late to change completed event evidence');
            $this->fail('Completed event accepted a new fulfilment.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('venue_event_task_fulfillments')->count());
        }
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'event_operation.task_fulfilled')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->expectException(QueryException::class);
        DB::table('venue_event_task_fulfillments')->where('id', $key)
            ->delete();
    }

    public function test_staff_can_witness_reviewed_task_through_livewire_but_not_twice(): void
    {
        [$company, , $hall, $people, $customer] = $this->fixture();
        $draft = $this->review($company, $hall, $people['owner'], $customer, '2027-04-08', 'morning');
        $id = (string) Str::uuid();
        $planning = app(OperationsPlanningService::class);
        $planning->create($people['owner'], $company->id, $id, $draft,
            'Set welcome chairs at entrance', 'Approved pre-event welcome setup');
        $planning->setReviewed($people['owner'], $company->id, $id, 1, true,
            'Owner reviewed welcome chair preparation');
        $this->be($people['manager']);
        $reservation = $this->confirm($people['manager'], $company, $draft);
        config()->set('event_controls.recording_enabled', true);
        $this->be($people['operations']);
        Livewire::withQueryParams(['company' => $company->id])
            ->test(EventOperations::class)
            ->call('selectReservation', $reservation['id'])
            ->assertSee('Set welcome chairs at entrance')
            ->assertDontSee('Record task performed')
            ->set('startClock', '08:06')
            ->set('reason', 'Actual arrival was witnessed')
            ->call('startEvent')->assertHasNoErrors()
            ->assertSee('Record task performed')
            ->set('taskReason', 'Welcome chairs were placed as requested')
            ->call('fulfillSelectedTask', $id)->assertHasNoErrors()
            ->assertSee('performed')
            ->assertDontSee('Record task performed');
        $this->assertSame(1, DB::table('venue_event_task_fulfillments')->count());
        $this->assertSame(0, DB::table('venue_invoice_payments')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

}
