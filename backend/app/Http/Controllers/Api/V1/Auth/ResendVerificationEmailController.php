<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

use function Illuminate\Support\defer;

class ResendVerificationEmailController extends Controller
{
    public function __invoke(ResendVerificationRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            defer(fn () => $user->sendEmailVerificationNotification());
        }

        return response()->json([
            'message' => "Si un compte en attente de vérification existe pour cette adresse, un nouvel email vient d'être envoyé.",
        ], 202);
    }
}