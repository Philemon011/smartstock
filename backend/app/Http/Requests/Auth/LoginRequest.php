<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge([
            'email' => is_string($email) ? mb_strtolower(trim($email)) : $email,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Indiquez votre adresse email.',
            'email.string' => "Votre adresse email n'est pas valide.",
            'email.email' => "Cette adresse email n'est pas valide. Exemple : nom@entreprise.com",
            'email.max' => 'Votre adresse email ne peut pas dépasser 254 caractères.',
            'password.required' => 'Saisissez votre mot de passe.',
            'password.string' => "Votre mot de passe n'est pas valide.",
            'password.max' => 'Votre mot de passe ne peut pas dépasser 128 caractères.',
            'remember.boolean' => "Le choix « Se souvenir de moi » n'est pas valide.",
        ];
    }
}