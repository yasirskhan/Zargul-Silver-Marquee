<?php

return [
    // Public booking remains OFF until an authenticated customer hold and
    // independently verified 5% deposit boundary is released.
    'enabled' => env('VENUE_PUBLIC_BOOKING_ENABLED', false),
];