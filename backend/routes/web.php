<?php

use Illuminate\Support\Facades\Route;

Route::get('/', \App\Http\Controllers\PublishedVenuePageController::class);
 
// Finite public slugs only. Never intercept /admin, /livewire, or private APIs.
Route::get('/{pageSlug}', \App\Http\Controllers\PublishedVenuePageController::class)
    ->whereIn('pageSlug', ['halls', 'packages', 'services', 'gallery', 'about', 'contact', 'privacy', 'terms']);

// Host-bound public enquiry intake; no booking, hold or payment.
Route::post('/enquiry', \App\Http\Controllers\PublicEnquiryController::class)
    ->middleware(['throttle:5,1'])
    ->name('public.enquiry.store');

// Serve approved, optimized WebP bytes only when referenced by the CURRENT
// published site page. No direct private disk or unapproved upload route.
Route::get('/_venue-media/{pageSlug}/{mediaId}.webp',
    \App\Http\Controllers\PublishedVenueMediaController::class)
    ->whereIn('pageSlug', ['home', 'halls', 'packages', 'services', 'gallery', 'about',
        'contact', 'privacy', 'terms'])
    ->whereUuid('mediaId');

Route::get('/book', [\App\Http\Controllers\PublicBookingController::class, 'show'])->name('public.booking.start');
Route::post('/book/quote', [\App\Http\Controllers\PublicBookingController::class, 'quote'])
    ->middleware('throttle:20,1')->name('public.booking.quote');
Route::post('/book/review', [\App\Http\Controllers\PublicBookingController::class, 'review'])
    ->middleware('throttle:20,1')->name('public.booking.review');
Route::post('/book/submit', [\App\Http\Controllers\PublicBookingController::class, 'submit'])
    ->middleware('throttle:10,1')->name('public.booking.submit');
Route::post('/book/cancel', [\App\Http\Controllers\PublicBookingController::class, 'cancel'])
    ->middleware('throttle:10,1')->name('public.booking.cancel');
Route::get('/robots.txt', \App\Http\Controllers\PublishedVenueRobotsController::class);
Route::get('/sitemap.xml', \App\Http\Controllers\PublishedVenueSitemapController::class);

// Signed email-ownership verification used by staff invitations.
Route::get('/email/verify/{id}/{hash}', \App\Http\Controllers\VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

// First-Owner bootstrap: operator-issued, one-time, rate limited.
Route::get('/install/owner/{token}', [\App\Http\Controllers\OwnerBootstrapController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')->name('installation.owner.show');
Route::post('/install/owner/{token}', [\App\Http\Controllers\OwnerBootstrapController::class, 'accept'])
    ->where('token', '[a-f0-9]{64}')->middleware(['throttle:6,1'])->name('installation.owner.accept');

// Test-only route; the controller independently enforces testing mode and
// CENTRAL_LOCAL_STATUS_TEST before changing any license fixture.
Route::get('/__test/site-layout/{layout}', App\Http\Controllers\TestSiteLayoutController::class)
    ->whereIn('layout', ['classic', 'editorial', 'minimal']);

Route::get('/__test/license-window/{mode}', App\Http\Controllers\TestLicenseWindowController::class)
    ->whereIn('mode', ['valid', 'warning', 'grace', 'expired']);

// Staff invitation acceptance: token-bound, single-use, rate limited.
Route::get('/staff-invitation/{token}', [\App\Http\Controllers\StaffInvitationAcceptanceController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')->name('staff.invitation.show');
Route::post('/staff-invitation/{token}', [\App\Http\Controllers\StaffInvitationAcceptanceController::class, 'accept'])
    ->where('token', '[a-f0-9]{64}')->middleware(['throttle:6,1'])->name('staff.invitation.accept');

// Authenticated, company-authorized private CMS media preview/thumbnail.
// Only an authorized editor/publisher can fetch these bytes; no public path.
Route::get('/site-media/{mediaId}', \App\Http\Controllers\SiteMediaPreviewController::class)
    ->whereUuid('mediaId')
    ->middleware(['throttle:120,1'])
    ->name('site.media.preview');

// Owner/admin encrypted audit archive download; ciphertext only.
Route::get('/audit-export/{exportId}', \App\Http\Controllers\AuditExportDownloadController::class)
    ->whereUuid('exportId')
    ->middleware(['throttle:30,1'])
    ->name('audit.export.download');

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

Route::get('/_private/hall-inventory/purchase-evidence/{companyId}/{hallId}/{purchaseId}/{fileId}',
    \App\Http\Controllers\PrivatePurchaseEvidenceDownloadController::class)
    ->whereUuid('companyId')->whereUuid('hallId')->whereUuid('purchaseId')->whereUuid('fileId')
    ->middleware('throttle:20,1')->name('inventory.purchase-evidence.download');
