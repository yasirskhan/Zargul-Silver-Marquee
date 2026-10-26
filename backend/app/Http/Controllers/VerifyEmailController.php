<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

final class VerifyEmailController
{
    public function __invoke(Request $request, string $id, string $hash)
    {
        $user = User::query()->findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->route('filament.admin.auth.login')
            ->with('status', 'Email verified. Sign in and enrol in MFA.');
    }
}
