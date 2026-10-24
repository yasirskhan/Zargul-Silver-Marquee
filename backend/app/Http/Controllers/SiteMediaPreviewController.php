<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SiteMediaVariantService;
use App\Services\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Authenticated, company-authorized private media preview/thumbnail endpoint.
 * Never exposes object keys, raw staged uploads to anonymous users, or
 * arbitrary filesystem paths.
 */
final class SiteMediaPreviewController
{
    public function __invoke(Request $request, string $mediaId)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $variant = $request->query('variant', 'preview');
        abort_unless(in_array($variant, ['thumb', 'preview'], true), 404);

        $asset = DB::table('site_media')->where('id', $mediaId)->first();
        abort_unless($asset !== null, 404);

        $access = app(TenantAccess::class);
        abort_unless(
            $access->canEditWebsite($user, $asset->company_id)
            || $access->canPublishWebsite($user, $asset->company_id),
            403,
        );
        abort_unless(DB::table('users')->where('id', $user->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists(), 403);
        abort_unless(in_array($asset->status, ['pending', 'approved'], true), 404);

        $bytes = Storage::disk('local')->get($asset->object_key);
        abort_unless(is_string($bytes)
            && hash_equals($asset->sha256, hash('sha256', $bytes)), 404);

        $variantBytes = app(SiteMediaVariantService::class)
            ->webpFit($bytes, $asset->mime_type, $variant === 'thumb' ? 320 : 1200);

        return response($variantBytes['bytes'], 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=300, no-transform',
            'Pragma' => 'private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
