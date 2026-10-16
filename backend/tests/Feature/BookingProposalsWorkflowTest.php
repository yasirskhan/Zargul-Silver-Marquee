<?php

namespace Tests\Feature;

use App\Filament\Pages\BookingProposals;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\BookingDraftService;
use App\Services\BookingDraftReviewService;
use App\Services\CustomerProfileService;
use App\Services\HallProgramRulesService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class BookingProposalsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver Marquee', 'slug' => 'proposal-review-main']);
        $hall = Hall::create([
            'company_id' => $company->id, 'slug' => 'review-hall',
            'name' => 'Review Hall', 'capacity' => 300,
        ]);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'review-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        $session = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end,
            'setupMinutes' => 20, 'cleaningMinutes' => 20, 'graceMinutes' => 15,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
            'rentalRateMinor' => 0,
        ];
        app(HallProgramRulesService::class)->configure(
            $owner, $company->id, $hall->id,
            ['morning' => $session('08:00', '11:00'),
                'noon' => $session('12:00', '15:00'),
                'evening' => $session('17:00', '22:00')],
            'PKR', 0, 'Configured for internal planning test only',
        );
        $customerId = (string) Str::uuid();
        app(CustomerProfileService::class)->create(
            $owner, $company->id, $customerId,
            'Private Customer Name', 'private@example.test', '+1 555 123 4000',
            'Private internal fixture, no live customer',
        );
        $draftId = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $owner, $company->id, $draftId, $hall->id, $customerId,
            '2026-12-16', 'evening', 120, 'Initial private planning proposal',
        );

        return [$company, $hall, $owner, $membership, $draftId, $customerId];
    }

    public function test_owner_edits_existing_draft_with_livewire_and_persists_immutable_redacted_history(): void
    {
        [$company, $hall, $owner, , $draftId] = $this->fixture();
        $component = Livewire::test(BookingProposals::class)
            ->call('selectDraft', $draftId)
            ->assertSet('selectedRevision', 1)
            ->assertSee('Save draft changes')
            ->assertDontSee('Private Customer Name')
            ->assertDontSee('private@example.test')
            ->set('editDate', '2026-12-18')
            ->set('editProgram', 'morning')
            ->set('editGuests', '145')
            ->set('editReason', 'Update planning numbers internally')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSet('selectedRevision', 2)
            ->assertSet('selectedStatus', 'draft');
        $this->assertSame('2026-12-18', $component->get('editDate'));
        $saved = app(BookingDraftService::class)->current($owner, $company->id, $draftId);
        $this->assertSame(145, $saved['guests']);
        $this->assertSame(2, DB::table('booking_draft_events')->where('booking_id', $draftId)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'booking_draft.updated')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $serialized = json_encode([
            DB::table('booking_draft_events')->where('booking_id', $draftId)->get(),
            DB::table('audit_events')->where('entity_id', $draftId)->get(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private Customer Name', $serialized);
        $this->assertStringNotContainsString('private@example.test', $serialized);
    }

    public function test_owner_can_submit_saved_draft_and_withdraw_without_reserving_a_slot(): void
    {
        [$company, , $owner, , $draftId] = $this->fixture();
        $component = Livewire::test(BookingProposals::class)
            ->call('selectDraft', $draftId)
            ->set('editReason', 'Ready for owner internal review')
            ->call('submitReview')
            ->assertHasNoErrors()
            ->assertSet('selectedStatus', 'under_review')
            ->assertSet('selectedRevision', 2)
            ->assertDontSee('Save draft changes')
            ->set('editReason', 'Withdraw internal planning proposal')
            ->call('withdrawDraft')
            ->assertHasNoErrors()
            ->assertSet('selectedStatus', 'withdrawn')
            ->assertSet('selectedRevision', 3);
        $this->assertSame('withdrawn',
            app(BookingDraftService::class)->current($owner, $company->id, $draftId)['status']);
        $this->assertSame(['created', 'submitted', 'withdrawn'],
            DB::table('booking_draft_events')->where('booking_id', $draftId)
                ->orderBy('revision')->pluck('action')->all());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('hall_slot_claim_events')->count());

        $this->expectException(ConflictHttpException::class);
        app(BookingDraftReviewService::class)->withdraw(
            $owner, $company->id, $draftId, 3, 'Never reopen withdrawn proposal',
        );
    }

    public function test_dirty_unsaved_form_and_stale_revision_cannot_change_review_status(): void
    {
        [$company, $hall, $owner, , $draftId, $customerId] = $this->fixture();
        $component = Livewire::test(BookingProposals::class)
            ->call('selectDraft', $draftId)
            ->set('editGuests', '200')
            ->set('editReason', 'Attempt review without saving')
            ->call('submitReview')->assertHasErrors(['editReason']);
        $this->assertSame('draft', DB::table('booking_drafts')->where('id', $draftId)->value('status'));

        $component->call('selectDraft', $draftId);
        app(BookingDraftService::class)->update(
            $owner, $company->id, $draftId, $hall->id, $customerId,
            '2026-12-17', 'noon', 150, 1, 'Another owner session wrote first',
        );
        $component->set('editReason', 'Attempt stale status update')
            ->call('submitReview')->assertHasErrors(['editReason'])
            ->call('saveDraft')->assertHasErrors(['editReason']);
        $this->assertSame('draft', DB::table('booking_drafts')->where('id', $draftId)->value('status'));
        $this->assertSame(2, (int) DB::table('booking_drafts')->where('id', $draftId)->value('revision'));
    }

    public function test_revoked_or_wrong_company_actor_cannot_edit_or_open_other_company_proposals(): void
    {
        [$company, , $owner, $membership, $draftId] = $this->fixture();
        $other = Company::create(['name' => 'Other Company', 'slug' => 'proposal-review-other']);
        $component = Livewire::test(BookingProposals::class)->call('selectDraft', $draftId);
        $component->set('companyId', $other->id)->assertForbidden();

        $component = Livewire::test(BookingProposals::class)->call('selectDraft', $draftId);
        $membership->update(['is_active' => false]);
        // Filament conceals a formerly visible page after role revocation (404).
        $component->set('editReason', 'Attempt by revoked operator')->call('submitReview')->assertNotFound();
        $this->assertSame(1, (int) DB::table('booking_drafts')->where('id', $draftId)->value('revision'));
        $membership->update(['is_active' => true]);

        $manager = User::create([
            'name' => 'Manager', 'email' => 'review-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        Livewire::test(BookingProposals::class)->assertForbidden();
    }

    public function test_review_audit_insert_failure_rolls_back_status_revision_and_history(): void
    {
        [$company, , $owner, , $draftId] = $this->fixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_review_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.action = 'booking_draft.submitted' THEN
        RAISE EXCEPTION 'Injected review audit failure';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_review_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_review_audit_test()');
        try {
            app(BookingDraftReviewService::class)->submit(
                $owner, $company->id, $draftId, 1, 'Attempt review with failed audit',
            );
            $this->fail('Review transition committed despite audit failure.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected review audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_review_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_review_audit_test()');
        }
        $this->assertSame('draft', DB::table('booking_drafts')->where('id', $draftId)->value('status'));
        $this->assertSame(1, (int) DB::table('booking_drafts')->where('id', $draftId)->value('revision'));
        $this->assertSame(1, DB::table('booking_draft_events')->where('booking_id', $draftId)->count());
    }
}
