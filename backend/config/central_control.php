<?php

return [
    // Vendor-managed bootstrap trust anchor. Do not accept a user-supplied URL,
    // request header, installer text field or dynamic IP as a substitute.
    'trusted_base_url' => 'https://lichall.hafzatech.com',
    // Separate release gate; no activation/central calls during normal operation.
    'activation_enabled' => (bool) env('VENUE_CENTRAL_ACTIVATION_ENABLED', false),
    'check_interval_days' => 15,
];
