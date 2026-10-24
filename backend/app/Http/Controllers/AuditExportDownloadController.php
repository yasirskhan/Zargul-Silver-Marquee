<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner/admin-only encrypted audit archive download. The archive remains
 * ciphertext; secure off-site storage and restore approval are separate.
 */
final class AuditExportDownloadController
{
    public function __invoke(Request $request, string $exportId)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(Str::isUuid($exportId), 404);
        abort_unless(DB::table('users')->where('id', $user->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists(), 403);

        $export = DB::table('audit_export_requests')->where('id', $exportId)->first();
        abort_unless($export !== null, 404);
        abort_unless(app(TenantAccess::class)->canReadAuditHistory($user, $export->company_id), 403);

        DB::transaction(function () use ($user, $export): void {
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $export->company_id,
                'actor_id' => $user->getKey(),
                'action' => 'audit.export_downloaded',
                'entity_type' => 'audit_export',
                'entity_id' => $export->id,
                'before_values' => null,
                'after_values' => json_encode([
                    'entryCount' => (int) $export->entry_count,
                    'sha256' => $export->sha256,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode([], JSON_THROW_ON_ERROR),
            ]);
        }, 3);

        return response($export->encrypted_payload, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="venuedesk-audit-'.$export->id.'.enc"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
