<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'application' => 'VenueDesk',
    'state' => 'foundation',
    'notice' => 'The customer website and booking APIs are not yet connected.',
]));
