<?php

namespace App\Http\Controllers;

use App\Services\OwnerBootstrapService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OwnerBootstrapController
{
    public function show(string $token)
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 404);

        return response()->view('install.owner-bootstrap', ['token' => $token])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function accept(Request $request, string $token, OwnerBootstrapService $service)
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        try {
            $service->accept($token, $data['password']);
        } catch (ConflictHttpException|\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['bootstrap' => $exception->getMessage()]);
        }

        return redirect()->route('filament.admin.auth.login')->with('status',
            'Owner account created. Sign in and enrol in MFA before administering the venue.');
    }
}
