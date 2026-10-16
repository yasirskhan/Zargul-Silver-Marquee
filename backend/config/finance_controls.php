<?php

return [
    // NEVER enable until approved currency fractions, contracts, cash custody,
    // taxes, reconciliation, and operator recovery controls are signed off.
    // Draft quotation previews are nonbinding and do not require this switch.
    'posting_enabled' => (bool) env('VENUE_FINANCE_POSTING_ENABLED', false),
];
