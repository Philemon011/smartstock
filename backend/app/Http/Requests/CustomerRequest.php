<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class CustomerRequest extends FormRequest
{
    private const OPTIONAL_TEXT = ['phone', 'address', 'city', 'notes'];

    public function authorize(): bool
    {
        $customer = $this->route('customer');

        if ($customer instanceof Customer) {
            Gate::authorize('update', $customer);
        } else {
            Gate::authorize('create', Customer::class);
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

        if ($this->has('credit_limit')) {
            $limit = $this->input('credit_limit');
            $limit = is_int($limit) ? (string) $limit : $limit;
            $normalized['credit_limit'] = $this->clean($limit, collapse: false);
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'min:2', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{6,30}$/'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'credit_limit' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $decimals = $this->decimals();
                    $pattern = $decimals > 0 ? '/^\d{1,13}(\.\d{1,'.$decimals.'})?$/' : '/^\d{1,13}$/';

                    if (! preg_match($pattern, $value)) {
                        $fail($decimals > 0
                            ? "Le plafond de crédit est invalide : saisissez un montant avec au plus {$decimals} décimales."
                            : 'Le plafond de crédit est invalide : saisissez un montant entier, sans décimales.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Indiquez le nom du client.',
            'name.string' => "Le nom du client n'est pas valide.",
            'name.min' => 'Le nom du client doit contenir au moins 2 caractères.',
            'name.max' => 'Le nom du client ne peut pas dépasser 150 caractères.',
            'phone.string' => "Le numéro de téléphone n'est pas valide.",
            'phone.max' => 'Le numéro de téléphone ne peut pas dépasser 30 caractères.',
            'phone.regex' => "Ce numéro de téléphone n'est pas valide. Exemple : +229 01 02 03 04",
            'email.string' => "L'adresse email n'est pas valide.",
            'email.email' => "Cette adresse email n'est pas valide. Exemple : client@exemple.com",
            'email.max' => "L'adresse email ne peut pas dépasser 254 caractères.",
            'address.string' => "L'adresse n'est pas valide.",
            'address.max' => "L'adresse ne peut pas dépasser 255 caractères.",
            'city.string' => "La ville n'est pas valide.",
            'city.max' => 'La ville ne peut pas dépasser 100 caractères.',
            'notes.string' => 'Les notes ne sont pas valides.',
            'notes.max' => 'Les notes ne peuvent pas dépasser 500 caractères.',
            'credit_limit.string' => "Le plafond de crédit n'est pas valide.",
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkCreditLimitRight($validator)];
    }

    /**
     * Seuls le propriétaire et les administrateurs fixent ou changent le plafond de crédit.
     */
    private function checkCreditLimitRight(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || ! $this->has('credit_limit')) {
            return;
        }

        $current = app(CurrentTenant::class);

        if ($current->role()->canManageStructure()) {
            return;
        }

        $currency = $current->tenant()->currency;
        $customer = $this->route('customer');

        $old = $customer instanceof Customer && $customer->credit_limit !== null
            ? Money::normalize((string) $customer->credit_limit, $currency)
            : null;

        $submitted = $this->input('credit_limit');
        $new = $submitted === null ? null : Money::normalize((string) $submitted, $currency);

        if ($old !== $new) {
            $validator->errors()->add(
                'credit_limit',
                'Seuls le propriétaire et les administrateurs peuvent définir le plafond de crédit.',
            );
        }
    }

    private function decimals(): int
    {
        return Money::decimals(app(CurrentTenant::class)->tenant()->currency);
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