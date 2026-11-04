<?php

namespace App\Http\Requests;

use App\Models\Unit;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unit = $this->route('unit');

        if ($unit instanceof Unit) {
            Gate::authorize('update', $unit);
        } else {
            Gate::authorize('create', Unit::class);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'symbol'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->clean($this->input($field), collapse: $field === 'name');
            }
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
                'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $exists = Unit::query()
                        ->where('name_key', Unit::keyFor($value))
                        ->when($this->currentUnit(), fn ($query, Unit $unit) => $query->whereKeyNot($unit->getKey()))
                        ->exists();

                    if ($exists) {
                        $fail("Une unité nommée « {$value} » existe déjà. Choisissez un autre nom.");
                    }
                },
            ],
            'symbol' => [
                'bail',
                'required',
                'string',
                'max:10',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $other = Unit::query()
                        ->where('symbol_key', Unit::symbolKey($value))
                        ->when($this->currentUnit(), fn ($query, Unit $unit) => $query->whereKeyNot($unit->getKey()))
                        ->first();

                    if ($other !== null) {
                        $fail("Le symbole « {$value} » est déjà utilisé par l'unité « {$other->name} ».");
                    }
                },
            ],
            'allows_decimals' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => "Indiquez le nom de l'unité.",
            'name.string' => "Le nom de l'unité n'est pas valide.",
            'name.min' => "Le nom de l'unité doit contenir au moins 2 caractères.",
            'name.max' => "Le nom de l'unité ne peut pas dépasser 50 caractères.",
            'symbol.required' => "Indiquez le symbole de l'unité, par exemple kg.",
            'symbol.string' => "Le symbole n'est pas valide.",
            'symbol.max' => 'Le symbole ne peut pas dépasser 10 caractères.',
            'allows_decimals.required' => 'Indiquez si cette unité accepte les décimales.',
            'allows_decimals.boolean' => 'Le choix sur les décimales n\'est pas valide.',
        ];
    }

    private function currentUnit(): ?Unit
    {
        $unit = $this->route('unit');

        return $unit instanceof Unit ? $unit : null;
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