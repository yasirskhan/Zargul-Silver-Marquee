<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;

final class TestLicenseWindowController
{
    public function __invoke(string $mode)
    {
        abort_if(app()->environment('production'), 404);
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless(in_array($mode, ['valid', 'warning', 'grace', 'expired'], true), 404);
        $code = Artisan::call('venuedesk:test-license-window', ['mode' => $mode]);
        if ($code !== 0) {
            return response()->json(['mode' => $mode, 'error' => 'fixture_creation_failed'], 500)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json(['mode' => $mode, 'exit' => $code])->header('Cache-Control', 'no-store');
    }
}
