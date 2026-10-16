<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\AuditExportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class AuditExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Silver Venue', 'slug' => 'audit-export']);
        $foreign = Company::create(['name' => 'Other', 'slug' => 'audit-export-foreign']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'audit-export-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        $membership = CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->be($owner);

        return [$company, $foreign, $owner, $membership];
    }

    private function event(string $companyId, int $actorId, string $action): void
    {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actorId, 'action' => $action,
            'entity_type' => 'hall', 'entity_id' => (string) Str::uuid(),
            'before_values' => json_encode(['status' => 'prior'], JSON_THROW_ON_ERROR),
            'after_values' => json_encode(['status' => 'updated'], JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => 'Owner changed a setting'], JSON_THROW_ON_ERROR),
        ]);
    }

    private function dates(): array
    {
        $day = now()->toDateString();

        return ['fromDate' => $day, 'toDate' => $day];
    }

    public function test_owner_gets_only_encrypted_own_company_bundle_and_redacted_export_audit(): void
    {
        [$company, $foreign, $owner] = $this->fixture();
        $this->event($company->id, $owner->id, 'hall.safe');
        $this->event($foreign->id, $owner->id, 'hall.foreign_private');
        $result = app(AuditExportService::class)->prepare(
            $owner, $company->id, $this->dates(), 'Secure owner audit review'
        );

        $this->assertSame(1, $result['entryCount']);
        $this->assertStringNotContainsString('hall.safe', $result['encryptedPayload']);
        $json = Crypt::decryptString($result['encryptedPayload']);
        $this->assertSame(hash('sha256', $json), $result['sha256']);
        $archive = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('venuedesk-audit-v1', $archive['format']);
        $this->assertSame($company->id, $archive['companyId']);
        $this->assertSame('hall.safe', $archive['entries'][0]['action']);
        $this->assertStringNotContainsString('hall.foreign_private', $json);
        $audit = DB::table('audit_events')->where('action', 'audit.export_prepared')->first();
        $this->assertNotNull($audit);
        $this->assertSame($result['exportId'], $audit->entity_id);
        $this->assertStringNotContainsString('hall.safe', json_encode($audit, JSON_THROW_ON_ERROR));
        $this->assertSame(3, DB::table('audit_events')->count());
    }

    public function test_manager_foreign_company_and_revoked_owner_are_denied(): void
    {
        [$company, $foreign, $owner, $membership] = $this->fixture();
        $service = app(AuditExportService::class);
        try {
            $service->prepare($owner, $foreign->id, $this->dates(), 'Foreign attempt');
            $this->fail('Foreign export was authorized.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $manager = User::create([
            'name' => 'Manager', 'email' => 'audit-export-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        $this->be($manager);
        try {
            $service->prepare($manager, $company->id, $this->dates(), 'Manager attempt');
            $this->fail('Manager export was authorized.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $membership->update(['is_active' => false]);
        $this->be($owner);
        $this->expectException(AuthorizationException::class);
        $service->prepare($owner, $company->id, $this->dates(), 'Revoked attempt');
    }

    public function test_invalid_window_reason_and_unexpected_filters_do_not_write_audit(): void
    {
        [$company, , $owner] = $this->fixture();
        $service = app(AuditExportService::class);
        foreach ([
            [[], 'Valid reason'],
            [['fromDate' => '2026-02-30', 'toDate' => '2026-03-01'], 'Valid reason'],
            [['fromDate' => '2026-01-01', 'toDate' => '2026-02-05'], 'Valid reason'],
            [$this->dates(), '<script>bad</script>'],
            [array_merge($this->dates(), ['secretFilter' => 'x']), 'Valid reason'],
        ] as [$filters, $reason]) {
            try {
                $service->prepare($owner, $company->id, $filters, $reason);
                $this->fail('Invalid protected audit export was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_audit_insertion_failure_never_yields_encrypted_export(): void
    {
        [$company, , $owner] = $this->fixture();
        $this->event($company->id, $owner->id, 'hall.change');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_audit_export_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.action = 'audit.export_prepared' THEN
        RAISE EXCEPTION 'Injected export audit failure';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_audit_export_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_audit_export_test()');
        try {
            app(AuditExportService::class)->prepare(
                $owner, $company->id, $this->dates(), 'Export must be audited'
            );
            $this->fail('Audit failure returned export ciphertext.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected export audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_audit_export_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_audit_export_test()');
        }
        $this->assertSame(1, DB::table('audit_events')->count());
    }
    public function test_explicit_request_key_returns_exact_same_ciphertext_without_duplicate_audit(): void
    {
        [$company, , $owner, $membership] = $this->fixture();
        $this->event($company->id, $owner->id, 'hall.first');
        $key = (string) Str::uuid();
        $service = app(AuditExportService::class);
        $first = $service->prepare($owner, $company->id, $this->dates(),
            'Owner encrypted review bundle', $key);
        $this->event($company->id, $owner->id, 'hall.later');
        $repeat = $service->prepare($owner, $company->id, $this->dates(),
            'Owner encrypted review bundle', $key);

        $this->assertSame($first, $repeat);
        $this->assertSame($key, $first['exportId']);
        $this->assertSame(1, $first['entryCount']);
        $this->assertSame(1, DB::table('audit_export_requests')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'audit.export_prepared')->count());
        $this->assertStringNotContainsString('hall.first',
            DB::table('audit_export_requests')->value('encrypted_payload'));

        $membership->update(['is_active' => false]);
        try {
            $service->prepare($owner, $company->id, $this->dates(),
                'Owner encrypted review bundle', $key);
            $this->fail('Revoked owner retrieved cached export.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_request_key_reuse_with_changed_filters_or_reason_is_refused(): void
    {
        [$company, , $owner] = $this->fixture();
        $key = (string) Str::uuid();
        $service = app(AuditExportService::class);
        $service->prepare($owner, $company->id, $this->dates(), 'Original owner reason', $key);
        foreach ([
            [$this->dates(), 'Different owner reason'],
            [array_merge($this->dates(), ['action' => 'hall.only']), 'Original owner reason'],
        ] as [$filters, $reason]) {
            try {
                $service->prepare($owner, $company->id, $filters, $reason, $key);
                $this->fail('A reused request key returned different export content.');
            } catch (ConflictHttpException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, DB::table('audit_export_requests')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'audit.export_prepared')->count());
    }

    public function test_one_statement_snapshot_has_no_page_boundary_duplicates_and_enforces_cap(): void
    {
        [$company, , $owner] = $this->fixture();
        for ($i = 0; $i < 205; $i++) {
            $this->event($company->id, $owner->id, 'hall.page_boundary');
        }
        $result = app(AuditExportService::class)->prepare(
            $owner, $company->id, $this->dates(), 'Single snapshot for private export',
        );
        $snapshot = json_decode(Crypt::decryptString($result['encryptedPayload']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(205, $result['entryCount']);
        $this->assertCount(205, $snapshot['entries']);
        $ids = array_column($snapshot['entries'], 'id');
        $this->assertCount(205, array_unique($ids));
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'audit.export_prepared')->where('company_id', '!=', $company->id)->count());

        // A new request over the hard limit cannot yield partial ciphertext.
        for ($i = 0; $i < 296; $i++) {
            $this->event($company->id, $owner->id, 'hall.over_limit');
        }
        try {
            app(AuditExportService::class)->prepare(
                $owner, $company->id, $this->dates(), 'No partial export above limit',
            );
            $this->fail('Over-limit audit export was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, DB::table('audit_export_requests')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'audit.export_prepared')->count());
    }

    public function test_export_request_registry_is_immutable_and_invalid_uuid_is_rejected(): void
    {
        [$company, , $owner] = $this->fixture();
        $service = app(AuditExportService::class);
        try {
            $service->prepare($owner, $company->id, $this->dates(),
                'Invalid opaque key', 'not-a-uuid');
            $this->fail('Invalid request ID was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $result = $service->prepare($owner, $company->id, $this->dates(),
            'Valid owner request key', (string) Str::uuid());
        try {
            DB::transaction(function () use ($result): void {
                DB::table('audit_export_requests')->where('id', $result['exportId'])
                    ->update(['entry_count' => 250]);
            });
            $this->fail('Export request metadata was mutable.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, DB::table('audit_export_requests')->count());
    }

}
