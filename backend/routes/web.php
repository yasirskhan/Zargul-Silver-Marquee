<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'application' => 'VenueDesk',
    'state' => 'foundation',
    'notice' => 'The customer website and booking APIs are not yet connected.',
]));

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
