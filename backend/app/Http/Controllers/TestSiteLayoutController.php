<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SiteBrandingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class TestSiteLayoutController
{
    public function __invoke(string $layout, SiteBrandingService $branding)
    {
        abort_unless(app()->environment('testing')
            && config('public_site.test_fallback') === true, 404);
        abort_unless(in_array($layout, ['classic', 'editorial', 'minimal'], true), 404);
        $website = DB::table('websites')->where('name', 'Phase 10 Browser Fixture')
            ->where('is_active', true)->first();
        abort_if($website === null, 404);
        $owner = User::query()->where('email', 'browser-e2e-inventory-owner-c@example.test')->first();
        abort_if($owner === null, 404);
        Auth::login($owner);
        $draft = (int) $website->branding_draft_revision;
        $content = $draft > 0
            ? DB::table('site_branding_revisions')->where('company_id', $website->company_id)
                ->where('website_id', $website->id)->where('revision_number', $draft)->value('content')
            : null;
        $current = is_string($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
        $payload = array_merge([
            'siteName' => 'Phase 10 Browser Venue',
            'tagline' => 'Independent published website fixture',
            'themePreset' => 'sage',
            'fontPreset' => 'modern',
            'logoMediaId' => null,
            'faviconMediaId' => null,
            'footerText' => 'Phase 10 published footer',
        ], is_array($current) ? $current : [], ['layoutPreset' => $layout]);
        $saved = $branding->saveDraft($owner, $website->company_id, $website->id,
            $payload, $draft, 'Synthetic Phase 10 publication for '.$layout);
        $branding->publish($owner, $website->company_id, $website->id, $saved,
            'Synthetic Phase 10 publication for '.$layout);
        Auth::logout();

        return response()->json(['layout' => $layout, 'revision' => $saved]);
    }
}