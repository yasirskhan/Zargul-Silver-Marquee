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

    public function test_owner_saves_company_and_halls_but_partial_setup_cannot_finalize(): void
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
            ->assertSet('status', 'in_progress')
            ->call('complete')
            ->assertHasErrors(['wizard'])
            ->assertSet('status', 'in_progress');

        $this->assertSame(1, DB::table('halls')->where('company_id', $company->id)->count());
        $this->assertSame('in_progress', DB::table('installation_wizard_states')->value('status'));
        $this->assertNull(DB::table('installation_wizard_states')->value('completed_at'));
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'installation.wizard_completed')->count());
        $this->assertSame(2, DB::table('audit_events')
            ->where('action', 'installation.wizard_step_saved')->count());
    }

    public function test_staff_mode_advances_to_review_at_step_eight(): void
    {
        [$company] = $this->fixture();
        $hallId = (string) \Illuminate\Support\Str::uuid();
        DB::table('halls')->insert([
            'id' => $hallId, 'company_id' => $company->id, 'slug' => 'staff-stage-hall',
            'name' => 'Staff Stage Hall', 'capacity' => 100,
            'included_chairs' => 0, 'included_tables' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('installation_wizard_states')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $company->id,
            'step' => 8, 'revision' => 10, 'status' => 'in_progress',
            'data' => json_encode([
                'company' => ['legalName' => 'Wizard Livewire', 'contactEmail' => '',
                    'contactPhone' => '', 'address' => '', 'currency' => 'PKR',
                    'timezone' => 'Asia/Karachi'],
                'halls' => [['id' => $hallId, 'name' => 'Staff Stage Hall',
                    'address' => '', 'phone' => '', 'capacity' => 100]],
                'completedStages' => ['company', 'halls', 'programs', 'pricing', 'addons',
                    'branding', 'payments'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test(VenueSetupWizard::class)
            ->assertSet('step', 8)
            ->set('staffMode', 'owner_only')
            ->call('saveAndContinue')
            ->assertHasNoErrors()
            ->assertSet('step', 9);
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
