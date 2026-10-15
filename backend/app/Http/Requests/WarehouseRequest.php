<?php

namespace App\Http\Requests;

use App\Models\Warehouse;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $warehouse = $this->route('warehouse');

        if ($warehouse instanceof Warehouse) {
            Gate::authorize('update', $warehouse);
        } else {
            Gate::authorize('create', Warehouse::class);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'address', 'city', 'phone'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->clean($this->input($field));
            }
        }

        if ($this->has('code')) {
            $code = $this->clean($this->input('code'));
            $normalized['code'] = is_string($code) ? mb_strtoupper($code) : $code;
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
                'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $exists = Warehouse::query()
                        ->where('name_key', Warehouse::keyFor($value))
                        ->when($this->ignoredId(), fn ($query, string $id) => $query->whereKeyNot($id))
                        ->exists();

                    if ($exists) {
                        $fail("Un entrepôt nommé « {$value} » existe déjà. Choisissez un autre nom.");
                    }
                },
            ],
            'code' => [
                'bail',
                'nullable',
                'string',
                'max:30',
                'regex:/^[A-Z0-9._-]+$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $exists = Warehouse::query()
                        ->where('code', $value)
                        ->when($this->ignoredId(), fn ($query, string $id) => $query->whereKeyNot($id))
                        ->exists();

                    if ($exists) {
                        $fail('Ce code est déjà utilisé par un autre entrepôt.');
                    }
                },
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{6,30}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => "Indiquez le nom de l'entrepôt.",
            'name.string' => "Le nom de l'entrepôt n'est pas valide.",
            'name.min' => "Le nom de l'entrepôt doit contenir au moins 2 caractères.",
            'name.max' => "Le nom de l'entrepôt ne peut pas dépasser 100 caractères.",
            'code.string' => "Le code n'est pas valide.",
            'code.max' => 'Le code ne peut pas dépasser 30 caractères.',
            'code.regex' => 'Le code ne peut contenir que des lettres, des chiffres, des points, des tirets et des tirets bas.',
            'address.string' => "L'adresse n'est pas valide.",
            'address.max' => "L'adresse ne peut pas dépasser 255 caractères.",
            'city.string' => "La ville n'est pas valide.",
            'city.max' => 'La ville ne peut pas dépasser 100 caractères.',
            'phone.string' => "Le numéro de téléphone n'est pas valide.",
            'phone.max' => 'Le numéro de téléphone ne peut pas dépasser 30 caractères.',
            'phone.regex' => 'Ce numéro de téléphone n\'est pas valide. Exemple : +229 01 02 03 04',
        ];
    }

    private function ignoredId(): ?string
    {
        $warehouse = $this->route('warehouse');

        return $warehouse instanceof Warehouse ? (string) $warehouse->getKey() : null;
    }

    private function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = preg_replace('/\s+/u', ' ', trim($value));

        return $clean === '' ? null : $clean;
    }
}