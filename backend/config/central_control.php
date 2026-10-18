<?php

return [
    // Vendor-managed bootstrap trust anchor. Do not accept a user-supplied URL,
    // request header, installer text field or dynamic IP as a substitute.
    'trusted_base_url' => 'https://lichall.hafzatech.com',
    // Separate release gate; no activation/central calls during normal operation.
    'activation_enabled' => (bool) env('VENUE_CENTRAL_ACTIVATION_ENABLED', false),
    'check_interval_days' => 15,
    // Distinct operator release gate; cron command is inert until production
    // signing keys, HTTPS service and enrollment are approved and provisioned.
    'scheduled_checks_enabled' => (bool) env('VENUE_CENTRAL_SCHEDULED_CHECKS_ENABLED', false),
    // Independent manual action rollout, controlled by vendor/operator.
    'manual_checks_enabled' => (bool) env('VENUE_CENTRAL_MANUAL_CHECKS_ENABLED', false),
    // Independent support delivery gate; support is not linked to license expiry.
    'support_enabled' => (bool) env('VENUE_CENTRAL_SUPPORT_ENABLED', false),
    // Dedicated HTTPS support outbox worker release; OFF independently.
    'support_delivery_enabled' => (bool) env('VENUE_CENTRAL_SUPPORT_DELIVERY_ENABLED', false),
    // Separate reply inbox/pull rollout; defaults OFF, independent of license checks.
    'support_reply_pull_enabled' => (bool) env('VENUE_CENTRAL_SUPPORT_REPLY_PULL_ENABLED', false),
    // Populate ONLY in the vendor-built installer with audited pinned Ed25519
    // public keys. Never trust keys downloaded from an unauthenticated host.
    'trusted_signing_public_keys' => [],
];
