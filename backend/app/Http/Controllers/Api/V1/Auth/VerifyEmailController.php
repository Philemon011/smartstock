<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\VerifyUserEmail;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    public function __invoke(
        Request $request,
        string $id,
        string $hash,
        VerifyUserEmail $verifyUserEmail,
    ): RedirectResponse {
        if (! $request->hasValidSignature()) {
            return $this->redirectToFrontend('invalid');
        }

        $user = User::find($id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->redirectToFrontend('invalid');
        }

        $newlyVerified = $verifyUserEmail->handle($user);

        return $this->redirectToFrontend($newlyVerified ? 'verified' : 'already-verified');
    }

    private function redirectToFrontend(string $status): RedirectResponse
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return redirect()->away("{$base}/verify-email?status={$status}");
    }
}