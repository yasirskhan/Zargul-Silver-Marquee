<?php

return [
    // Never allow real deposits unless invoice/tax, treasury custody and
    // reconciliation policies have been approved and enabled separately.
    'enabled' => env('VENUE_CUSTOMER_PAYMENT_ENABLED', false),
];
