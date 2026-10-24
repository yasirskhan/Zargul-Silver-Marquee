<?php

namespace Tests\Feature;

use App\Filament\Pages\VenueSetupWizard;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class VenueSetupWizardLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Wizard Livewire', 'slug' => 'wizard-livewire']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'wizard-livewire-owner@example.test',
            'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        return [$company, $owner];
    }

    public function test_owner_saves_company_and_halls_and_completes_wizard(): void
    {
        [$company] = $this->fixture();
        $component = Livewire::test(VenueSetupWizard::class)
            ->assertSet('companyId', $company->id)
            ->set('company.legalName', 'Zargul Wizard Venue')
            ->set('company.contactEmail', 'wizard@example.test')
            ->set('company.currency', 'PKR')
            ->set('company.timezone', 'Asia/Karachi')
            ->call('saveAndContinue')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $component->set('halls.0.name', 'Main Hall')
            ->set('halls.0.capacity', 250)
            ->set('halls.0.address', 'Main address')
            ->call('saveAndContinue')
            ->assertHasNoErrors()
            ->assertSet('step', 3)
            ->call('complete')
            ->assertHasNoErrors()
            ->assertSet('status', 'completed');

        $this->assertSame(1, DB::table('halls')->where('company_id', $company->id)->count());
        $this->assertSame('completed', DB::table('installation_wizard_states')->value('status'));
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'installation.wizard_completed')->count());
    }

    public function test_manager_cannot_open_owner_wizard(): void
    {
        [$company] = $this->fixture();
        $manager = User::create([
            'name' => 'Manager', 'email' => 'wizard-livewire-manager@example.test',
            'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        Livewire::test(VenueSetupWizard::class)->assertForbidden();
    }
}
