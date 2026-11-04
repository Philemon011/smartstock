<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SupplierRequest extends FormRequest
{
    private const OPTIONAL_TEXT = ['contact_name', 'phone', 'address', 'city', 'notes'];

    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        if ($supplier instanceof Supplier) {
            Gate::authorize('update', $supplier);
        } else {
            Gate::authorize('create', Supplier::class);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('name')) {
            $normalized['name'] = $this->clean($this->input('name'), collapse: true);
        }

        foreach (self::OPTIONAL_TEXT as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->clean($this->input($field), collapse: $field !== 'notes');
            }
        }

        if ($this->has('email')) {
            $email = $this->clean($this->input('email'), collapse: false);
            $normalized['email'] = is_string($email) ? mb_strtolower($email) : $email;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'min:2',
                'max:150',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $current = $this->route('supplier');

                    $exists = Supplier::query()
                        ->where('name_key', Supplier::keyFor($value))
                        ->when($current instanceof Supplier, fn ($query) => $query->whereKeyNot($current->getKey()))
                        ->exists();

                    if ($exists) {
                        $fail("Un fournisseur nommé « {$value} » existe déjà. Choisissez un autre nom.");
                    }
                },
            ],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{6,30}$/'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Indiquez le nom du fournisseur.',
            'name.string' => "Le nom du fournisseur n'est pas valide.",
            'name.min' => 'Le nom du fournisseur doit contenir au moins 2 caractères.',
            'name.max' => 'Le nom du fournisseur ne peut pas dépasser 150 caractères.',
            'contact_name.string' => "Le nom du contact n'est pas valide.",
            'contact_name.max' => 'Le nom du contact ne peut pas dépasser 100 caractères.',
            'phone.string' => "Le numéro de téléphone n'est pas valide.",
            'phone.max' => 'Le numéro de téléphone ne peut pas dépasser 30 caractères.',
            'phone.regex' => "Ce numéro de téléphone n'est pas valide. Exemple : +229 01 02 03 04",
            'email.string' => "L'adresse email n'est pas valide.",
            'email.email' => "Cette adresse email n'est pas valide. Exemple : contact@fournisseur.com",
            'email.max' => "L'adresse email ne peut pas dépasser 254 caractères.",
            'address.string' => "L'adresse n'est pas valide.",
            'address.max' => "L'adresse ne peut pas dépasser 255 caractères.",
            'city.string' => "La ville n'est pas valide.",
            'city.max' => 'La ville ne peut pas dépasser 100 caractères.',
            'notes.string' => 'Les notes ne sont pas valides.',
            'notes.max' => 'Les notes ne peuvent pas dépasser 500 caractères.',
        ];
    }

    private function clean(mixed $value, bool $collapse): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = $collapse ? preg_replace('/\s+/u', ' ', trim($value)) : trim($value);

        return $clean === '' ? null : $clean;
    }
}