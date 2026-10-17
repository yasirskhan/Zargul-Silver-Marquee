<?php
return [
    // No actual customer quote release until issuer identity, tax, consent,
    // pricing and jurisdiction-specific issuance rules are signed off.
    'issuance_enabled' => env('VENUE_QUOTE_ISSUANCE_ENABLED', false),
];
