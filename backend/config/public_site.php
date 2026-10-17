<?php

return [
    // Explicit operator-controlled single-domain release gate. No public
    // company/domain discovery and no tenant-selected routing via Host input.
    'enabled' => env('VENUE_PUBLIC_SITE_ENABLED', false),
    'host' => env('VENUE_PUBLIC_SITE_HOST', ''),
    'company_id' => env('VENUE_PUBLIC_SITE_COMPANY_ID', ''),
    'website_id' => env('VENUE_PUBLIC_SITE_WEBSITE_ID', ''),
    // Production always requires verified ownership regardless of this value.
    // Tests can opt in to assert the same release gate.
    'require_verified_domain' => env('VENUE_PUBLIC_SITE_REQUIRE_VERIFIED_DOMAIN', false),
    // Separate operator approval required before allowing search engines.
    'indexing_enabled' => env('VENUE_PUBLIC_SITE_INDEXING_ENABLED', false),
];
