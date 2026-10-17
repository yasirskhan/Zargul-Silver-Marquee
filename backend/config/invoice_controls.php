<?php
return [
    // Both company legal/tax review and a separate production release are
    // required. Do not interpret an unconfigured rate as a tax exemption.
    'issuance_enabled' => env('VENUE_INVOICE_ISSUANCE_ENABLED', false),
    'tax_rate_bps' => env('VENUE_INVOICE_APPROVED_TAX_BPS'),
];
