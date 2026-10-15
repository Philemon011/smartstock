<?php

namespace App\Http\Requests\Auth;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => PasswordRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => "Le lien de réinitialisation est incomplet. Utilisez le lien reçu par email.",
            'token.string' => "Le lien de réinitialisation n'est pas valide.",
            'token.max' => "Le lien de réinitialisation n'est pas valide.",
            'email.required' => 'Indiquez votre adresse email.',
            'email.string' => "Votre adresse email n'est pas valide.",
            'email.email' => "Cette adresse email n'est pas valide. Exemple : nom@entreprise.com",
            'email.max' => 'Votre adresse email ne peut pas dépasser 254 caractères.',
            ...PasswordRules::messages(),
        ];
    }
}