<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\AuditExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Recovery-side verification of encrypted audit archives (AUD-001). */
class AuditExportRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_encrypted_archive_verifies_and_tampering_or_wrong_digest_fails(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'audit-recovery']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'audit-recovery-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->be($owner);
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id,
            'actor_id' => $owner->id, 'action' => 'hall.audit_recovery',
            'entity_type' => 'hall', 'entity_id' => (string) Str::uuid(),
            'before_values' => json_encode([], JSON_THROW_ON_ERROR),
            'after_values' => json_encode(['ok' => true], JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => 'Recovery evidence'], JSON_THROW_ON_ERROR),
        ]);
        $service = app(AuditExportService::class);
        $result = $service->prepare($owner, $company->id, [
            'fromDate' => now()->toDateString(), 'toDate' => now()->toDateString(),
        ], 'Owner recovery verification');

        $path = sys_get_temp_dir().'/venuedesk-audit-'.Str::uuid().'.enc';
        file_put_contents($path, $result['encryptedPayload']);
        try {
            $this->artisan('venuedesk:verify-audit-export', ['path' => $path])
                ->assertExitCode(0);
            $this->artisan('venuedesk:verify-audit-export', [
                'path' => $path, '--expected-sha256' => $result['sha256'],
            ])->assertExitCode(0);
            $this->artisan('venuedesk:verify-audit-export', [
                'path' => $path, '--expected-sha256' => str_repeat('0', 64),
            ])->assertExitCode(1);

            file_put_contents($path, 'tampered-ciphertext');
            $this->artisan('venuedesk:verify-audit-export', ['path' => $path])
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, DB::table('audit_export_requests')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'audit.export_prepared')->count());
    }
}
