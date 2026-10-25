<?php

namespace App\Http\Controllers;

use App\Services\StaffInvitationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Public token-bound invitation acceptance; no account is privileged before MFA. */
final class StaffInvitationAcceptanceController
{
    public function show(string $token)
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 404);

        return response()->view('staff.accept-invitation', ['token' => $token])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function accept(Request $request, string $token, StaffInvitationService $service)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        try {
            $service->accept($token, $data['name'], $data['password']);
        } catch (ConflictHttpException|\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        return redirect()->route('filament.admin.auth.login')->with('status',
            'Invitation accepted. Verify your email and enrol in MFA before using privileged access.');
    }
}
