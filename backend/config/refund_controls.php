<?php

return [
    // Separate manually-approved custody and real-world refund settlement gate.
    // A recorded reversal must never be treated as proof an external transfer occurred.
    'enabled' => env('VENUE_CUSTOMER_REFUND_ENABLED', false),
];
