<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __invoke(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => "Cette requête n'est pas autorisée.",
                'code' => 'stateful_required',
            ], 400);
        }

        $email = $request->validated('email');
        $password = $request->validated('password');
        $throttleKey = 'login|'.sha1($email).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw new ThrottleRequestsException(
                headers: ['Retry-After' => RateLimiter::availableIn($throttleKey)],
            );
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            // Même coût de calcul que pour un compte existant : le temps de réponse ne révèle rien.
            Hash::make($password);
            $valid = false;
        } else {
            $valid = Hash::check($password, $user->password);
        }

        if (! $valid) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'credentials' => 'Email ou mot de passe incorrect.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => "Votre adresse email n'est pas encore vérifiée. Ouvrez le lien reçu par email, ou demandez-en un nouveau.",
                'code' => 'email_not_verified',
            ], 403);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return (new MeResource($user->load('memberships.tenant')))->response();
    }
}