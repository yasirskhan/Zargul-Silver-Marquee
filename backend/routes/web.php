<?php

use Illuminate\Support\Facades\Route;

Route::get('/', \App\Http\Controllers\PublishedVenuePageController::class);
 
// Finite public slugs only. Never intercept /admin, /livewire, or private APIs.
Route::get('/{pageSlug}', \App\Http\Controllers\PublishedVenuePageController::class)
    ->whereIn('pageSlug', ['halls', 'packages', 'services', 'gallery', 'about', 'contact', 'privacy', 'terms']);

// Serve approved, optimized WebP bytes only when referenced by the CURRENT
// published site page. No direct private disk or unapproved upload route.
Route::get('/_venue-media/{pageSlug}/{mediaId}.webp',
    \App\Http\Controllers\PublishedVenueMediaController::class)
    ->whereIn('pageSlug', ['home', 'halls', 'packages', 'services', 'gallery', 'about',
        'contact', 'privacy', 'terms'])
    ->whereUuid('mediaId');

/**
 * Only Telegram itself may call this endpoint. In production, reject plain
 * HTTP even behind an accidentally misconfigured reverse proxy.
 */
Route::post('/integrations/telegram/approval-webhook', function (\Illuminate\Http\Request $request) {
    abort_unless(app()->environment('testing') || $request->isSecure(), 403);
    $secret = $request->header('X-Telegram-Bot-Api-Secret-Token');
    abort_unless(is_string($secret) && strlen($secret) >= 24, 403);
    try {
        app(\App\Services\TelegramApprovalAlertService::class)->acceptWebhook(
            $request->json()->all(), $secret
        );
    } catch (\Illuminate\Auth\Access\AuthorizationException | \InvalidArgumentException) {
        abort(403);
    }
    return response()->json(['ok' => true]);
})->middleware('throttle:30,1');
