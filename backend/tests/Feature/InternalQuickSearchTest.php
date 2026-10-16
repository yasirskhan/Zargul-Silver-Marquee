<?php

namespace Tests\Feature;

use App\Filament\Pages\InternalQuickSearch;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\BookingDraftReviewService;
use App\Services\BookingDraftService;
use App\Services\CustomerProfileService;
use App\Services\FinanceControlService;
use App\Services\HallProgramRulesService;
use App\Services\InternalQuickSearchService;
use App\Services\OperationsPlanningService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class InternalQuickSearchTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Internal Search Venue', 'slug' => 'internal-search-venue']);
        $foreign = Company::create(['name' => 'Other Internal Venue', 'slug' => 'other-internal-search']);
        $members = [];
        foreach (['owner', 'admin', 'manager'] as $role) {
            $user = User::create(['name' => ucfirst($role),
                'email' => 'search-'.$role.'@example.test', 'password' => 'synthetic']);
            $user->markEmailAsVerified();
            $membership = CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $user->id, 'role' => $role,
            ]);
            $members[$role] = [$user, $membership];
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($members['owner'][0]);

        $hall = Hall::create(['company_id' => $company->id,
            'name' => 'Private Hall', 'slug' => 'private-hall', 'capacity' => 220]);
        $rule = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 10, 'cleaningMinutes' => 10,
            'graceMinutes' => 10, 'overtimeIntervalMinutes' => 30,
            'overtimeRateMinor' => 0, 'rentalRateMinor' => 0,
        ];
        app(HallProgramRulesService::class)->configure(
            $members['owner'][0], $company->id, $hall->id, [
                'morning' => $rule('08:00', '11:00'),
                'noon' => $rule('12:00', '15:00'),
                'evening' => $rule('17:00', '22:00'),
            ], 'PKR', 0, 'Synthetic only internal search session rules'
        );
        $customer = (string) Str::uuid();
        app(CustomerProfileService::class)->create(
            $members['owner'][0], $company->id, $customer,
            'Private Synthetic Guest', 'contact-hidden@example.test',
            null, 'Synthetic only without live contacts'
        );
        $booking = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $members['owner'][0], $company->id, $booking, $hall->id,
            $customer, '2027-05-20', 'evening', 100, 'Internal private proposal'
        );
        $task = (string) Str::uuid();
        app(OperationsPlanningService::class)->create(
            $members['owner'][0], $company->id, $task,
            $booking, 'Arrange stage light cables', 'Internal setup planning only'
        );
        app(ApprovalWorkflowService::class)->request(
            $members['owner'][0], $company->id, (string) Str::uuid(),
            'expense', 1, 'PKR', null, 'Synthetic expense consideration'
        );
        app(BookingDraftReviewService::class)->submit(
            $members['owner'][0], $company->id, $booking, 1, 'Internal review only'
        );
        $preview = (string) Str::uuid();
        app(FinanceControlService::class)->preview(
            $members['owner'][0], $company->id, $preview, $booking, 2,
            'Frozen nonbinding search fixture'
        );
        return [$company, $foreign, $members, $booking, $task, $preview];
    }

    public function test_owner_uses_private_postgres_search_without_exposing_contacts(): void
    {
        [$company, , $members, $booking, $task, $preview] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $this->assertContains($booking, array_column(
            $service->search($members['owner'][0], $company->id, '2027-05-20'), 'id'
        ));
        $this->assertContains($task, array_column(
            $service->search($members['owner'][0], $company->id, 'stage'), 'id'
        ));
        $this->assertContains('approval', array_column(
            $service->search($members['owner'][0], $company->id, 'expense'), 'kind'
        ));
        $this->assertContains($preview, array_column(
            $service->search($members['owner'][0], $company->id, substr($preview, 0, 8)), 'id'
        ));
        $audits = $service->search($members['owner'][0], $company->id, 'operations');
        $this->assertContains('audit', array_column($audits, 'kind'));
        $results = json_encode(array_merge($audits, $service->search(
            $members['owner'][0], $company->id, 'stage'
        )));
        $this->assertStringNotContainsString('contact-hidden@example.test', $results);
        $this->assertStringNotContainsString('Private Synthetic Guest', $results);
        Livewire::test(InternalQuickSearch::class)
            ->assertSee('Private owner/admin reference search')
            ->set('searchTerm', 'stage')->call('runSearch')->assertHasNoErrors()
            ->assertSee('Arrange stage light cables')
            ->assertDontSee('contact-hidden@example.test');
    }

    public function test_cross_tenant_manager_and_revoked_owner_search_denied(): void
    {
        [$company, $foreign, $members] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        $this->be($members['manager'][0]);
        Livewire::test(InternalQuickSearch::class)->assertForbidden();
        try {
            $service->search($members['manager'][0], $company->id, 'stage');
            $this->fail('Manager accessed owner-only internal search.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($members['owner'][0]);
        try {
            $service->search($members['owner'][0], $foreign->id, 'stage');
            $this->fail('Owner searched an unrelated company.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $mounted = Livewire::test(InternalQuickSearch::class);
        $members['owner'][1]->update(['is_active' => false]);
        $mounted->call('runSearch')->assertForbidden();
    }

    public function test_term_and_result_bounds_reject_contact_and_large_queries(): void
    {
        [$company, , $members] = $this->fixture();
        $service = app(InternalQuickSearchService::class);
        foreach (['', 'ab', 'user@example.test', str_repeat('a', 81)] as $term) {
            try {
                $service->search($members['owner'][0], $company->id, $term);
                $this->fail('Invalid query was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $service->search($members['owner'][0], $company->id, 'stage', 41);
    }
}
