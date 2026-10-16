<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\AuditHistoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AuditHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver Venue', 'slug' => 'audit-search']);
        $foreign = Company::create(['name' => 'Other Venue', 'slug' => 'foreign-audit-search']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'audit-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->be($owner);

        return [$company, $foreign, $owner, $membership];
    }

    private function event(string $companyId, int $actorId, string $action, string $entityId): void
    {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actorId, 'action' => $action,
            'entity_type' => 'hall', 'entity_id' => $entityId,
            'before_values' => json_encode(['revision' => 1], JSON_THROW_ON_ERROR),
            'after_values' => json_encode(['revision' => 2], JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => 'Approved internal change'], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_owner_can_search_only_own_company_and_filter_action_entity_and_date(): void
    {
        [$company, $foreign, $owner] = $this->fixture();
        $target = (string) Str::uuid();
        $this->event($company->id, $owner->id, 'hall.program_rules_updated', $target);
        $this->event($company->id, $owner->id, 'hall.seating_updated', (string) Str::uuid());
        $this->event($foreign->id, $owner->id, 'hall.program_rules_updated', (string) Str::uuid());

        $service = app(AuditHistoryService::class);
        $today = now()->toDateString();
        $result = $service->search($owner, $company->id, [
            'action' => 'hall.program_rules_updated', 'entityType' => 'hall',
            'entityId' => $target, 'fromDate' => $today, 'toDate' => $today,
        ]);
        $this->assertCount(1, $result['entries']);
        $this->assertSame('hall', $result['entries'][0]['entityType']);
        $this->assertSame($target, $result['entries'][0]['entityId']);
        $this->assertSame(['revision' => 1], $result['entries'][0]['before']);
        $this->assertSame(['revision' => 2], $result['entries'][0]['after']);
        $this->assertSame('Approved internal change', $result['entries'][0]['context']['reason']);
        $this->assertFalse($result['hasMore']);
        $this->assertCount(2, $service->search($owner, $company->id)['entries']);
    }

    public function test_bounded_pagination_keeps_tenant_filter_on_every_page(): void
    {
        [$company, $foreign, $owner] = $this->fixture();
        $this->event($company->id, $owner->id, 'hall.one', (string) Str::uuid());
        $this->event($company->id, $owner->id, 'hall.two', (string) Str::uuid());
        $this->event($company->id, $owner->id, 'hall.three', (string) Str::uuid());
        $this->event($foreign->id, $owner->id, 'hall.foreign', (string) Str::uuid());
        $service = app(AuditHistoryService::class);
        $first = $service->search($owner, $company->id, [], 2);
        $second = $service->search($owner, $company->id, [], 2, 2);
        $this->assertCount(2, $first['entries']);
        $this->assertTrue($first['hasMore']);
        $this->assertCount(1, $second['entries']);
        $this->assertFalse($second['hasMore']);
        $ids = array_merge(array_column($first['entries'], 'id'), array_column($second['entries'], 'id'));
        $this->assertCount(3, array_unique($ids));
        $this->assertSame(4, DB::table('audit_events')->count());
    }

    public function test_manager_and_revoked_owner_cannot_search_audit_history(): void
    {
        [$company, , $owner, $membership] = $this->fixture();
        $manager = User::create([
            'name' => 'Manager', 'email' => 'audit-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $service = app(AuditHistoryService::class);
        $this->be($manager);
        try {
            $service->search($manager, $company->id);
            $this->fail('Manager saw owner audit history.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $membership->update(['is_active' => false]);
        $this->be($owner);
        $this->expectException(AuthorizationException::class);
        $service->search($owner, $company->id);
    }

    public function test_foreign_tenant_and_wrong_actor_are_denied(): void
    {
        [$company, $foreign, $owner] = $this->fixture();
        $service = app(AuditHistoryService::class);
        try {
            $service->search($owner, $foreign->id);
            $this->fail('Foreign audit scope accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $imposter = User::create([
            'name' => 'Other', 'email' => 'audit-imposter@example.test', 'password' => 'test-only',
        ]);
        $imposter->markEmailAsVerified();
        $this->expectException(AuthorizationException::class);
        $service->search($imposter, $company->id);
    }

    public function test_invalid_dates_filter_names_and_page_sizes_are_rejected(): void
    {
        [$company, , $owner] = $this->fixture();
        $service = app(AuditHistoryService::class);
        foreach ([
            [['fromDate' => '2026-02-30'], 20, 0],
            [['fromDate' => '2026-11-13', 'toDate' => '2026-11-12'], 20, 0],
            [['hidden' => 'secret'], 20, 0],
            [['action' => 'hall.*'], 20, 0],
            [[], 0, 0], [[], 101, 0], [[], 20, 5001],
        ] as [$filters, $limit, $offset]) {
            try {
                $service->search($owner, $company->id, $filters, $limit, $offset);
                $this->fail('Invalid audit search accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
