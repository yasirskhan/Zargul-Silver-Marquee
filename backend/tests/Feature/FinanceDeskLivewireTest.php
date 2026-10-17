<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceDesk;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\BookingDraftReviewService;
use App\Services\FinanceControlService;
use App\Services\BookingDraftService;
use App\Services\CustomerProfileService;
use App\Services\HallProgramRulesService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceDeskLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Finance Venue', 'slug' => 'finance-ui']);
        $other = Company::create(['name' => 'Unrelated', 'slug' => 'finance-ui-other']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand',
            'slug' => 'grand', 'capacity' => 150,
        ]);
        $people = [];
        foreach (['owner', 'admin', 'manager'] as $role) {
            $person = User::create([
                'name' => ucfirst($role), 'email' => 'finance-ui-'.$role.'@example.test',
                'password' => 'synthetic-test-only',
            ]);
            $person->markEmailAsVerified();
            $membership = CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $person->id, 'role' => $role,
            ]);
            $people[$role] = [$person, $membership];
        }
        $this->be($people['owner'][0]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $slot = static fn (string $from, string $to, int $price): array => [
            'start' => $from, 'end' => $to, 'setupMinutes' => 10,
            'cleaningMinutes' => 10, 'graceMinutes' => 10,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
            'rentalRateMinor' => $price,
        ];
        app(HallProgramRulesService::class)->configure(
            $people['owner'][0], $company->id, $hall->id, [
                'morning' => $slot('08:00', '11:00', 1000),
                'noon' => $slot('12:00', '15:00', 2000),
                'evening' => $slot('17:00', '22:00', 3000),
            ], 'PKR', 0, 'Synthetic approved venue rate'
        );
        $customer = (string) Str::uuid();
        app(CustomerProfileService::class)->create(
            $people['owner'][0], $company->id, $customer,
            'Fake Client', 'private-test@example.test', null, 'No live data'
        );
        $booking = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $people['owner'][0], $company->id, $booking, $hall->id,
            $customer, '2027-01-04', 'evening', 110, 'Unconfirmed proposal'
        );
        app(BookingDraftReviewService::class)->submit(
            $people['owner'][0], $company->id, $booking, 1, 'Review for pricing preview'
        );
        return [$company, $other, $people, $booking];
    }


    public function test_stale_authenticated_owner_cannot_open_finance_after_persisted_account_revocation(): void
    {
        [$company, , $people] = $this->fixture();
        $owner = $people['owner'][0];
        $this->assertTrue(FinanceDesk::canAccess());

        // Simulate account revocation after login without refreshing auth model.
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->assertTrue($owner->is_active);
        $this->assertFalse(FinanceDesk::canAccess());
        Livewire::test(FinanceDesk::class)->assertForbidden();

        DB::table('users')->where('id', $owner->id)
            ->update(['is_active' => true, 'email_verified_at' => null]);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse(FinanceDesk::canAccess());
        Livewire::test(FinanceDesk::class)->assertForbidden();
        $this->assertSame(0, DB::table('finance_quote_previews')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('finance_cash_closeouts')->count());
    }

    public function test_owner_creates_a_private_nonbinding_preview_without_client_contact(): void
    {
        [$company, , , $booking] = $this->fixture();
        Livewire::test(FinanceDesk::class)
            ->assertSee('Finance review')
            ->assertSee('posting is disabled')
            ->set('draftBookingId', $booking)
            ->set('previewReason', 'Synthetic venue rate review')
            ->call('createPreview')->assertHasNoErrors()
            ->assertSee('3000')
            ->assertSee('Hall Rental')
            ->assertSee('Evening')
            ->assertSee('rate 3000')
            ->assertDontSee('private-test@example.test');
        $this->assertSame(1, DB::table('finance_quote_previews')->where('company_id', $company->id)->count());
        $this->assertSame([[
            'code' => 'hall_rental', 'program' => 'evening',
            'quantity' => 1, 'rateMinor' => 3000, 'amountMinor' => 3000,
        ]], app(FinanceControlService::class)
            ->previews(\App\Models\User::findOrFail(auth()->id()), $company->id)[0]['lines']);
        $this->assertSame(0, DB::table('finance_journals')->count());
    }


    public function test_authorized_frozen_preview_reference_filters_exact_record_and_rejects_cross_tenant(): void
    {
        [$company, $other, $people, $booking] = $this->fixture();
        $actor = $people['owner'][0];
        $service = app(FinanceControlService::class);
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $service->preview($actor, $company->id, $first, $booking, 2,
            'First nonbinding synthetic price freeze');
        $service->preview($actor, $company->id, $second, $booking, 2,
            'Second nonbinding synthetic price freeze');

        $panel = Livewire::withQueryParams([
            'company' => $company->id, 'preview' => $first,
        ])->test(FinanceDesk::class)
            ->assertSet('previewId', $first)
            ->assertSee('Showing only selected frozen preview')
            ->assertSee('Show all company rental previews')
            ->assertSee('Preview '.$first)
            ->assertDontSee('Preview '.$second)
            ->assertDontSee('private-test@example.test');
        $this->assertSame([$first], array_column($panel->get('previews'), 'id'));
        $panel->call('showAllPreviews')->assertSet('previewId', '')
            ->assertSee('Preview '.$first)->assertSee('Preview '.$second);
        $this->assertCount(2, $panel->get('previews'));

        Livewire::withQueryParams(['company' => $company->id,
            'preview' => (string) Str::uuid()])
            ->test(FinanceDesk::class)->assertForbidden();
        Livewire::withQueryParams(['company' => $other->id, 'preview' => $first])
            ->test(FinanceDesk::class)->assertForbidden();
        $this->be($people['manager'][0]);
        Livewire::withQueryParams(['company' => $company->id, 'preview' => $first])
            ->test(FinanceDesk::class)->assertForbidden();
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
    }

    public function test_company_switch_discards_unsent_private_finance_fields_and_rotates_keys(): void
    {
        [$company, , $people, $booking] = $this->fixture();
        $second = Company::create(['name' => 'Second owned finance venue',
            'slug' => 'finance-second-owned']);
        CompanyMembership::create([
            'company_id' => $second->id, 'user_id' => $people['owner'][0]->id,
            'role' => 'owner',
        ]);

        $component = Livewire::test(FinanceDesk::class)
            ->assertSet('companyId', $company->id)
            ->set('draftBookingId', $booking)
            ->set('previewReason', 'Private Company A rental review')
            ->set('currency', 'PKR')
            ->set('openingMinor', '4500')
            ->set('journalReason', 'Company A opening only')
            ->set('closeDate', '2027-01-11')
            ->set('countedMinor', '4400')
            ->set('closeReason', 'Company A cash handoff')
            ->set('reversalReason', 'Company A historical correction');
        $keys = [
            'previewRequestKey', 'openingRequestKey',
            'reversalRequestKey', 'closeoutRequestKey',
        ];
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = $component->get($key);
        }

        $component->set('companyId', $second->id)
            ->assertSet('loadedCompanyId', $second->id)
            ->assertSet('draftBookingId', '')
            ->assertSet('previewReason', '')
            ->assertSet('currency', '')
            ->assertSet('openingMinor', '')
            ->assertSet('journalReason', '')
            ->assertSet('closeDate', now('UTC')->toDateString())
            ->assertSet('countedMinor', '')
            ->assertSet('closeReason', '')
            ->assertSet('reversalReason', '')
            ->assertSet('lastCloseResult', null)
            ->assertSet('previews', [])
            ->assertSet('journals', [])
            ->assertSet('closeouts', []);
        foreach ($before as $key => $old) {
            $this->assertNotSame($old, $component->get($key));
            $this->assertTrue(Str::isUuid($component->get($key)));
        }
        $this->assertSame(0, DB::table('finance_quote_previews')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('finance_cash_closeouts')->count());
        $this->assertSame(0, DB::table('finance_journal_lines')->count());
    }

    public function test_finance_livewire_keys_are_stable_until_success_and_then_rotated(): void
    {
        [$company, , , $booking] = $this->fixture();
        $component = Livewire::test(FinanceDesk::class);
        $initialPreview = $component->get('previewRequestKey');
        $initialOpen = $component->get('openingRequestKey');
        $this->assertTrue(Str::isUuid($initialPreview));
        $this->assertTrue(Str::isUuid($initialOpen));
        $component->set('draftBookingId', $booking)
            ->set('previewReason', 'Synthetic stable key test')
            ->call('createPreview')->assertHasNoErrors();
        $this->assertNotSame($initialPreview, $component->get('previewRequestKey'));
        $this->assertSame($initialOpen, $component->get('openingRequestKey'));
        $this->assertSame(1, DB::table('finance_quote_previews')->where('company_id', $company->id)->count());

        $component->set('currency', 'PKR')->set('openingMinor', '500')
            ->set('journalReason', 'Posting deliberately disabled')
            ->call('openCash')->assertHasErrors(['journalReason']);
        $this->assertSame($initialOpen, $component->get('openingRequestKey'));
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_finance_authorization_blocks_manager_and_foreign_company_selector(): void
    {
        [$company, $other, $people] = $this->fixture();
        $this->be($people['manager'][0]);
        Livewire::test(FinanceDesk::class)->assertForbidden();

        $this->be($people['admin'][0]);
        Livewire::test(FinanceDesk::class)
            ->assertSee('Finance review')
            ->set('companyId', $other->id)->assertForbidden();
        $this->assertSame(0, DB::table('finance_cash_closeouts')->where('company_id', $company->id)->count());
    }

    public function test_owner_livewire_cannot_bypass_disabled_finance_posting(): void
    {
        [, , $people] = $this->fixture();
        Livewire::test(FinanceDesk::class)
            ->set('currency', 'PKR')->set('openingMinor', '500')
            ->set('journalReason', 'Unsafe pre-release post')
            ->call('openCash')->assertHasErrors(['journalReason']);
        $this->assertSame(0, DB::table('finance_journals')->count());
        $people['owner'][1]->update(['is_active' => false]);
        Livewire::test(FinanceDesk::class)->assertForbidden();
    }

    public function test_owner_controlled_post_and_count_available_only_with_explicit_gate(): void
    {
        [$company] = $this->fixture();
        config()->set('finance_controls.posting_enabled', true);
        Livewire::test(FinanceDesk::class)
            ->set('currency', 'PKR')->set('openingMinor', '500')
            ->set('journalReason', 'Synthetic audited opening')
            ->call('openCash')->assertHasNoErrors()
            ->assertSee('500')
            ->set('closeDate', now('UTC')->toDateString())
            ->set('countedMinor', '475')
            ->set('closeReason', 'Synthetic counted variance')
            ->call('closeCash')->assertHasNoErrors()
            ->assertSee('variance -25');
        $this->assertSame(1, DB::table('finance_journals')->where('company_id', $company->id)->count());
        $this->assertSame(-25, DB::table('finance_cash_closeouts')->value('variance_minor'));
    }
}
