<?php

namespace Tests\Feature;

use App\Filament\Pages\BookingProposals;
use App\Filament\Pages\VenueOverview;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\HallAssignment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OwnerReviewQueueNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function companyAndOwner(string $slug): array
    {
        $company = Company::create(['name' => 'Synthetic '.$slug, 'slug' => $slug]);
        $hall = Hall::create(['company_id' => $company->id,
            'name' => $slug.' Hall', 'slug' => $slug.'-hall', 'capacity' => 200]);
        $owner = User::create(['name' => 'Owner '.$slug,
            'email' => $slug.'-owner@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);

        return [$company, $hall, $owner];
    }

    private function insertDraft(Company $company, Hall $hall, User $owner, string $status): string
    {
        $customer = (string) Str::uuid();
        $booking = (string) Str::uuid();
        DB::table('customer_profiles')->insert([
            'id' => $customer, 'company_id' => $company->id, 'created_by' => $owner->id,
            'name_ciphertext' => Crypt::encryptString('Private Customer Contact'),
        ]);
        DB::table('booking_drafts')->insert([
            'id' => $booking, 'company_id' => $company->id,
            'hall_id' => $hall->id, 'customer_id' => $customer,
            'event_date' => '2027-03-12', 'program' => 'evening',
            'requested_guests' => 50, 'status' => $status,
            'created_by' => $owner->id, 'revision' => 1,
        ]);

        return $booking;
    }

    public function test_owner_review_queue_deep_link_filters_status_and_company_without_pii(): void
    {
        [$company, $hall, $owner] = $this->companyAndOwner('owner-review-queue');
        [$foreign, $foreignHall, $otherOwner] = $this->companyAndOwner('foreign-review-queue');
        $review = $this->insertDraft($company, $hall, $owner, 'under_review');
        $draft = $this->insertDraft($company, $hall, $owner, 'draft');
        $foreignReview = $this->insertDraft($foreign, $foreignHall, $otherOwner, 'under_review');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        Livewire::test(VenueOverview::class)
            ->assertSet('summary.reviewDrafts', 1)
            ->assertSee('Open internal proposal review queue')
            ->assertDontSee('Private Customer Contact');

        Livewire::withQueryParams(['company' => $company->id, 'status' => 'under_review'])
            ->test(BookingProposals::class)
            ->assertSet('statusFilter', 'under_review')
            ->assertSee($review)
            ->assertDontSee($draft)
            ->assertDontSee($foreignReview)
            ->assertDontSee('Private Customer Contact');

        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_manager_cannot_see_owner_review_count_or_open_proposals(): void
    {
        [$company, $hall, $owner] = $this->companyAndOwner('manager-scope-queue');
        $this->insertDraft($company, $hall, $owner, 'under_review');
        $manager = User::create(['name' => 'Manager',
            'email' => 'queue-manager@example.test', 'password' => 'test-only']);
        $manager->markEmailAsVerified();
        $membership = CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $manager->id, 'role' => 'manager']);
        HallAssignment::create(['company_id' => $company->id,
            'hall_id' => $hall->id, 'membership_id' => $membership->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($manager);

        Livewire::test(VenueOverview::class)
            ->assertSet('summary.reviewDrafts', null)
            ->assertDontSee('Open internal proposal review queue');
        Livewire::withQueryParams(['company' => $company->id, 'status' => 'under_review'])
            ->test(BookingProposals::class)->assertForbidden();

        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(0, DB::table('finance_journals')->count());
    }
}
