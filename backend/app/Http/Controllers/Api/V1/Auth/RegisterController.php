<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Tenants\RegisterTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Notifications\RegistrationAttemptNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    private const NEUTRAL_MESSAGE = "Si cette adresse est valide, un email de confirmation vous a été envoyé. Ouvrez-le pour activer votre espace.";

    public function __invoke(RegisterRequest $request, RegisterTenant $registerTenant): JsonResponse
    {
        $data = $request->validated();

        $existing = User::where('email', $data['email'])->first();

        if ($existing !== null) {
            $existing->notify(new RegistrationAttemptNotification());

            return $this->neutralResponse();
        }

        try {
            $result = $registerTenant->handle($data);
        } catch (UniqueConstraintViolationException) {
            // Deux inscriptions identiques simultanées : la seconde est traitée comme un email existant.
            return $this->neutralResponse();
        }

        event(new Registered($result['user']));

        return $this->neutralResponse();
    }

    private function neutralResponse(): JsonResponse
    {
        return response()->json(['message' => self::NEUTRAL_MESSAGE], 202);
    }
}