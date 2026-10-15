<?php

namespace App\Http\Requests\Auth;

class ForgotPasswordRequest extends ResendVerificationRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
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
        ];
    }
}
