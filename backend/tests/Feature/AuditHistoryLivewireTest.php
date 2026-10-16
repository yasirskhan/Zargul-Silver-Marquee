<?php

namespace Tests\Feature;

use App\Filament\Pages\AuditHistory;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AuditHistoryLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver Marquee', 'slug' => 'audit-ui']);
        $foreign = Company::create(['name' => 'Private Other Venue', 'slug' => 'other-audit-ui']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'audit-ui-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        return [$company, $foreign, $owner, $membership];
    }

    private function record(string $companyId, int $actorId, string $action, string $entityId, string $reason = 'Approved change'): void
    {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actorId, 'action' => $action,
            'entity_type' => 'hall', 'entity_id' => $entityId,
            'before_values' => json_encode(['status' => 'old'], JSON_THROW_ON_ERROR),
            'after_values' => json_encode(['status' => 'new'], JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_owner_search_filters_company_action_entity_and_date_and_escapes_event_text(): void
    {
        [$company, $foreign, $owner] = $this->fixture();
        $target = (string) Str::uuid();
        $this->record($company->id, $owner->id, 'hall.seating_updated', $target, '<script>alert(1)</script>');
        $this->record($company->id, $owner->id, 'hall.program_rules_updated', (string) Str::uuid());
        $this->record($foreign->id, $owner->id, 'hall.private', (string) Str::uuid());

        $component = Livewire::test(AuditHistory::class)
            ->assertSee('Audit history')
            ->assertSee('hall.seating_updated')
            ->assertDontSee('hall.private')
            ->set('filters.action', 'hall.seating_updated')
            ->set('filters.entityType', 'hall')
            ->set('filters.entityId', $target)
            ->set('filters.fromDate', now()->toDateString())
            ->set('filters.toDate', now()->toDateString())
            ->call('applyFilters')
            ->assertHasNoErrors()
            ->assertSet('loadedCompanyId', $company->id);

        $this->assertCount(1, $component->get('entries'));
        $this->assertSame(['status' => 'old'], $component->get('entries')[0]['before']);
        $component->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame(3, DB::table('audit_events')->count());
    }

    public function test_owner_paginated_read_never_changes_history(): void
    {
        [$company, , $owner] = $this->fixture();
        for ($i = 0; $i < 27; $i++) {
            $this->record($company->id, $owner->id, 'hall.change', (string) Str::uuid());
        }

        $component = Livewire::test(AuditHistory::class);
        $this->assertCount(25, $component->get('entries'));
        $component->assertSet('hasMore', true)->call('nextPage')->assertSet('offset', 25);
        $this->assertCount(2, $component->get('entries'));
        $component->assertSet('hasMore', false)->call('previousPage')->assertSet('offset', 0);
        $this->assertSame(27, DB::table('audit_events')->count());
    }

    public function test_manager_unverified_and_revoked_owner_cannot_read_history(): void
    {
        [$company, , $owner, $membership] = $this->fixture();
        $component = Livewire::test(AuditHistory::class);
        $manager = User::create([
            'name' => 'Manager', 'email' => 'audit-ui-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        Livewire::test(AuditHistory::class)->assertForbidden();

        $this->be($owner);
        $membership->update(['is_active' => false]);
        $component->call('applyFilters')->assertForbidden();
        Livewire::test(AuditHistory::class)->assertForbidden();

        $membership->update(['is_active' => true]);
        $owner->forceFill(['email_verified_at' => null])->save();
        Livewire::test(AuditHistory::class)->assertForbidden();
    }

    public function test_company_switch_and_invalid_filters_do_not_expose_other_tenants(): void
    {
        [$company, $foreign, $owner] = $this->fixture();
        $this->record($company->id, $owner->id, 'hall.allowed', (string) Str::uuid());
        $this->record($foreign->id, $owner->id, 'hall.private', (string) Str::uuid());

        Livewire::test(AuditHistory::class)
            ->set('companyId', $foreign->id)->assertForbidden();

        $component = Livewire::test(AuditHistory::class)
            ->set('filters.action', 'hall.*')->call('applyFilters')
            ->assertHasErrors(['filters'])
            ->set('filters.action', '')
            ->set('filters.fromDate', '2026-02-30')
            ->call('applyFilters')->assertHasErrors(['filters']);

        $this->assertSame(2, DB::table('audit_events')->count());
    }

    public function test_authorized_owner_switches_company_without_reusing_prior_results(): void
    {
        [$company, $foreign, $owner] = $this->fixture();
        CompanyMembership::create([
            'company_id' => $foreign->id, 'user_id' => $owner->id, 'role' => 'admin',
        ]);
        $this->record($company->id, $owner->id, 'hall.original', (string) Str::uuid());
        $this->record($foreign->id, $owner->id, 'hall.other', (string) Str::uuid());

        $component = Livewire::test(AuditHistory::class)
            ->set('companyId', $foreign->id)
            ->assertSet('loadedCompanyId', $foreign->id)
            ->assertSee('hall.other')
            ->assertDontSee('hall.original');
        $this->assertCount(1, $component->get('entries'));
    }
}
