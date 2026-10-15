<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\VerifyUserEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request, VerifyUserEmail $verifyUserEmail): JsonResponse
    {
        $status = Password::reset(
            $request->safe()->only(['email', 'password', 'token']),
            function (User $user, string $password) use ($verifyUserEmail): void {
                $user->forceFill(['password' => $password])
                    ->setRememberToken(Str::random(60));
                $user->save();

                // Le mot de passe change : toutes les sessions ouvertes sont fermées.
                DB::table('sessions')->where('user_id', $user->getKey())->delete();

                // Avoir reçu ce lien prouve que l'adresse appartient à la personne.
                $verifyUserEmail->handle($user);

                event(new PasswordReset($user));
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.',
            ]);
        }

        return response()->json([
            'message' => 'Votre mot de passe a été modifié. Vous pouvez maintenant vous connecter.',
        ]);
    }
}