<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CompanyProfileRequest extends FormRequest
{
    private const SINGLE_LINE = ['phone', 'address', 'city', 'tax_id', 'registration_number', 'receipt_footer'];

    public function authorize(): bool
    {
        Gate::authorize('update', app(CurrentTenant::class)->tenant());

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('name')) {
            $normalized['name'] = $this->clean($this->input('name'));
        }

        foreach (self::SINGLE_LINE as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->clean($this->input($field));
            }
        }

        if ($this->has('email')) {
            $email = $this->clean($this->input('email'));
            $normalized['email'] = is_string($email) ? mb_strtolower($email) : $email;
        }

        if ($this->has('website')) {
            $site = $this->clean($this->input('website'));

            if (is_string($site) && ! preg_match('#^https?://#i', $site)) {
                $site = 'https://'.$site;
            }

            $normalized['website'] = $site;
        }

        if ($this->has('legal_mentions')) {
            $normalized['legal_mentions'] = $this->cleanMultiline($this->input('legal_mentions'));
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{6,30}$/'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'legal_mentions' => ['nullable', 'string', 'max:500'],
            'receipt_footer' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => "Indiquez le nom de l'entreprise.",
            'name.string' => "Le nom de l'entreprise n'est pas valide.",
            'name.min' => "Le nom de l'entreprise doit contenir au moins 2 caractères.",
            'name.max' => "Le nom de l'entreprise ne peut pas dépasser 120 caractères.",
            'phone.string' => "Le numéro de téléphone n'est pas valide.",
            'phone.max' => 'Le numéro de téléphone ne peut pas dépasser 30 caractères.',
            'phone.regex' => "Ce numéro de téléphone n'est pas valide. Exemple : +229 01 02 03 04",
            'email.string' => "L'adresse email n'est pas valide.",
            'email.email' => "Cette adresse email n'est pas valide. Exemple : contact@entreprise.com",
            'email.max' => "L'adresse email ne peut pas dépasser 254 caractères.",
            'address.string' => "L'adresse n'est pas valide.",
            'address.max' => "L'adresse ne peut pas dépasser 255 caractères.",
            'city.string' => 'La ville n\'est pas valide.',
            'city.max' => 'La ville ne peut pas dépasser 100 caractères.',
            'website.string' => "Le site web n'est pas valide.",
            'website.url' => "Ce site web n'est pas valide. Exemple : https://www.entreprise.com",
            'website.max' => 'Le site web ne peut pas dépasser 200 caractères.',
            'tax_id.string' => "Le numéro d'identification fiscale n'est pas valide.",
            'tax_id.max' => "Le numéro d'identification fiscale ne peut pas dépasser 50 caractères.",
            'registration_number.string' => "Le numéro de registre du commerce n'est pas valide.",
            'registration_number.max' => 'Le numéro de registre du commerce ne peut pas dépasser 50 caractères.',
            'legal_mentions.string' => 'Les mentions légales ne sont pas valides.',
            'legal_mentions.max' => 'Les mentions légales ne peuvent pas dépasser 500 caractères.',
            'receipt_footer.string' => 'Le message de fin de reçu n\'est pas valide.',
            'receipt_footer.max' => 'Le message de fin de reçu ne peut pas dépasser 200 caractères.',
        ];
    }

    private function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = preg_replace('/\s+/u', ' ', trim($value));

        return $clean === '' ? null : $clean;
    }

    /**
     * Garde les retours à la ligne (mentions légales sur plusieurs lignes), sans espaces parasites.
     */
    private function cleanMultiline(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $value);
        $text = preg_replace('/[ \t]+$/m', '', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}