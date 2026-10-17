<?php

return [
    // Internal hall catalog and stocktake UI. Real loan/balance posting remains
    // unavailable until independent counting and finance gates are certified.
    'catalog_enabled' => (bool) env('VENUE_INVENTORY_CATALOG_ENABLED', false),
    'stocktakes_enabled' => (bool) env('VENUE_INVENTORY_STOCKTAKES_ENABLED', false),
];
