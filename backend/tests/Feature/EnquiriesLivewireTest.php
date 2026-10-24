<?php

namespace Tests\Feature;

use App\Filament\Pages\Enquiries;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\VenueEnquiryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EnquiriesLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_triages_private_enquiry_and_manager_is_forbidden(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'enquiries-ui']);
        $websiteId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $websiteId, 'company_id' => $company->id, 'name' => 'Public Site',
        ]);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand Hall',
            'slug' => 'enquiries-ui-hall', 'capacity' => 250,
        ]);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'enquiries-ui-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $result = app(VenueEnquiryService::class)->submit($company->id, $websiteId, [
            'requestKey' => (string) Str::uuid(),
            'customerName' => 'Synthetic Private Enquirer',
            'email' => 'private-enquirer@example.test', 'phone' => '',
            'hallId' => $hall->id, 'eventDate' => now()->addDays(10)->toDateString(),
            'program' => 'noon', 'guests' => 80, 'message' => 'Private message',
            'consent' => true,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        Livewire::test(Enquiries::class)
            ->assertSee('Synthetic Private Enquirer')
            ->assertSee('Grand Hall')
            ->set('reasons.'.$result['id'], 'Owner contacted the customer')
            ->call('updateStatus', $result['id'], 'contacted')
            ->assertHasNoErrors();
        $this->assertSame('contacted', DB::table('venue_enquiries')->value('status'));

        $manager = User::create([
            'name' => 'Manager', 'email' => 'enquiries-ui-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        Livewire::test(Enquiries::class)->assertForbidden();
    }
}
