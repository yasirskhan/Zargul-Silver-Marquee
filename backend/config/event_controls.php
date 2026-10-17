<?php

return [
    // Separately approved from booking confirmation and financial release.
    // Synthetic integration tests explicitly enable recording.
    'recording_enabled' => env('VENUE_EVENT_RECORDING_ENABLED', false),
];
