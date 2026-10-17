<?php

return [
    // Operator-controlled release gate. Synthetic PostgreSQL acceptance tests
    // explicitly turn this on; no public payment, intake or real booking release.
    'confirmation_enabled' => env('VENUE_BOOKING_CONFIRMATION_ENABLED', false),
];
