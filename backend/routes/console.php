<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('venuedesk:status', function (): void {
    $this->components->info('VenueDesk: Phase 1 tenant foundation. No production bookings or money.');
})->purpose('Display non-production foundation status');
