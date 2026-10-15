<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

use function Illuminate\Support\defer;

class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        // Envoi différé : le temps de réponse est identique, que le compte existe ou non.
        defer(fn () => Password::sendResetLink(['email' => $email]));

        return response()->json([
            'message' => "Si un compte existe pour cette adresse, un email pour choisir un nouveau mot de passe vient d'être envoyé.",
        ], 202);
    }
}