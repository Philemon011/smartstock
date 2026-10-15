<?php

namespace App\Http\Requests\Auth;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'company_name' => $this->cleanText($this->input('company_name')),
            'name' => $this->cleanText($this->input('name')),
            'email' => $this->cleanEmail($this->input('email')),
            'country' => $this->cleanUpper($this->input('country')),
            'currency' => $this->cleanUpper($this->input('currency')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'min:2', 'max:120'],
            'country' => ['required', 'string', Rule::in(array_keys(config('tenants.countries')))],
            'currency' => ['required', 'string', Rule::in(array_keys(config('tenants.currencies')))],
            'name' => ['required', 'string', 'min:2', 'max:120'],
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
            'company_name.required' => "Indiquez le nom de votre entreprise.",
            'company_name.string' => "Le nom de l'entreprise n'est pas valide.",
            'company_name.min' => "Le nom de l'entreprise doit contenir au moins 2 caractères.",
            'company_name.max' => "Le nom de l'entreprise ne peut pas dépasser 120 caractères.",

            'country.required' => "Choisissez le pays de votre entreprise.",
            'country.string' => "Le pays choisi n'est pas valide.",
            'country.in' => "Ce pays n'est pas encore pris en charge.",

            'currency.required' => "Choisissez la devise de votre entreprise.",
            'currency.string' => "La devise choisie n'est pas valide.",
            'currency.in' => "Cette devise n'est pas prise en charge.",

            'name.required' => "Indiquez votre nom complet.",
            'name.string' => "Votre nom n'est pas valide.",
            'name.min' => "Votre nom doit contenir au moins 2 caractères.",
            'name.max' => "Votre nom ne peut pas dépasser 120 caractères.",

            'email.required' => "Indiquez votre adresse email.",
            'email.string' => "Votre adresse email n'est pas valide.",
            'email.email' => "Cette adresse email n'est pas valide. Exemple : nom@entreprise.com",
            'email.max' => "Votre adresse email ne peut pas dépasser 254 caractères.",

            ...PasswordRules::messages(),
        ];
    }

    private function cleanText(mixed $value): mixed
    {
        return is_string($value) ? preg_replace('/\s+/u', ' ', trim($value)) : $value;
    }

    private function cleanEmail(mixed $value): mixed
    {
        return is_string($value) ? mb_strtolower(trim($value)) : $value;
    }

    private function cleanUpper(mixed $value): mixed
    {
        return is_string($value) ? mb_strtoupper(trim($value)) : $value;
    }
}