<?php

return [
    // Vendor-managed bootstrap trust anchor. Do not accept a user-supplied URL,
    // request header, installer text field or dynamic IP as a substitute.
    'trusted_base_url' => 'https://lichall.hafzatech.com',
    // Separate release gate; no activation/central calls during normal operation.
    'activation_enabled' => (bool) env('VENUE_CENTRAL_ACTIVATION_ENABLED', false),
    'check_interval_days' => 15,
    // Populate ONLY in the vendor-built installer with audited pinned Ed25519
    // public keys. Never trust keys downloaded from an unauthenticated host.
    'trusted_signing_public_keys' => [],
];
