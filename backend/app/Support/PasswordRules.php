<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    /**
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'max:128',
            Password::min((int) config('auth.password_min_length'))->uncompromised(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field = 'password'): array
    {
        return [
            "{$field}.required" => 'Choisissez un mot de passe.',
            "{$field}.string" => "Votre mot de passe n'est pas valide.",
            "{$field}.max" => 'Votre mot de passe ne peut pas dépasser 128 caractères.',
            "{$field}.min" => 'Votre mot de passe doit contenir au moins :min caractères.',
        ];
    }
}