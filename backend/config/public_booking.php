<?php

return [
    // Public booking remains OFF until an authenticated customer hold and
    // independently verified 5% deposit boundary is released.
    'enabled' => env('VENUE_PUBLIC_BOOKING_ENABLED', false),
    'holds_enabled' => env('VENUE_PUBLIC_BOOKING_HOLDS_ENABLED', false),
    'hold_minutes' => (int) env('VENUE_PUBLIC_BOOKING_HOLD_MINUTES', 15),
];