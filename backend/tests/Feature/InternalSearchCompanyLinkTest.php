<?php

namespace Tests\Feature;

use App\Filament\Pages\AuditHistory;
use App\Filament\Pages\BookingProposals;
use App\Filament\Pages\FinanceDesk;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InternalSearchCompanyLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_workspace_links_accept_current_company_and_reject_foreign_company(): void
    {
        $first = Company::create(['name' => 'Company One', 'slug' => 'links-company-one']);
        $second = Company::create(['name' => 'Company Two', 'slug' => 'links-company-two']);
        $foreign = Company::create(['name' => 'Unrelated Company', 'slug' => 'links-unrelated']);
        $owner = User::create([
            'name' => 'Synthetic Link Owner',
            'email' => 'link-owner@example.test', 'password' => 'testing-only',
        ]);
        $owner->markEmailAsVerified();
        foreach ([$first, $second] as $company) {
            CompanyMembership::create([
                'company_id' => $company->id,
                'user_id' => $owner->id,
                'role' => 'owner',
            ]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        foreach ([BookingProposals::class, FinanceDesk::class, AuditHistory::class] as $page) {
            Livewire::withQueryParams(['company' => $second->id])
                ->test($page)->assertSet('companyId', $second->id);
            Livewire::withQueryParams(['company' => $first->id])
                ->test($page)->assertSet('companyId', $first->id);
            Livewire::withQueryParams(['company' => $foreign->id])
                ->test($page)->assertForbidden();
        }
    }

    public function test_reception_cannot_use_owner_workspace_company_deep_links(): void
    {
        $company = Company::create(['name' => 'Restricted Company', 'slug' => 'links-restricted']);
        $employee = User::create([
            'name' => 'Synthetic Reception Employee',
            'email' => 'link-reception@example.test', 'password' => 'testing-only',
        ]);
        $employee->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $employee->id, 'role' => 'reception',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($employee);
        foreach ([BookingProposals::class, FinanceDesk::class, AuditHistory::class] as $page) {
            Livewire::withQueryParams(['company' => $company->id])
                ->test($page)->assertForbidden();
        }
    }
}
