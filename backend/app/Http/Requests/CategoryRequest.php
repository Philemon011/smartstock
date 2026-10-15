<?php

namespace App\Http\Requests;

use App\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        if ($category instanceof Category) {
            Gate::authorize('update', $category);
        } else {
            Gate::authorize('create', Category::class);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'description', 'parent_id'] as $field) {
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
                'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $parentId = $this->effectiveParentId();

                    $exists = Category::query()
                        ->where('name_key', Category::keyFor($value))
                        ->when(
                            $parentId === null,
                            fn ($query) => $query->whereNull('parent_id'),
                            fn ($query) => $query->where('parent_id', $parentId),
                        )
                        ->when($this->currentCategory()?->getKey(), fn ($query, string $id) => $query->whereKeyNot($id))
                        ->exists();

                    if ($exists) {
                        $fail("Une catégorie nommée « {$value} » existe déjà à cet endroit. Choisissez un autre nom.");
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'parent_id' => [
                'bail',
                'nullable',
                'string',
                'size:26',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $category = $this->currentCategory();
                    $parent = Category::query()->find($value);

                    if ($parent === null) {
                        $fail('Cette catégorie parente est introuvable.');
                    } elseif ($category !== null && $parent->id === $category->id) {
                        $fail('Une catégorie ne peut pas être sa propre parente.');
                    } elseif (! $parent->isRoot()) {
                        $fail('Choisissez une catégorie principale : une sous-catégorie ne peut pas avoir de sous-catégories.');
                    } elseif (! $parent->is_active) {
                        $fail('Cette catégorie parente est désactivée. Choisissez-en une autre.');
                    } elseif ($category !== null && $category->children()->exists()) {
                        $fail('Cette catégorie contient des sous-catégories : elle ne peut pas devenir une sous-catégorie.');
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
            'name.required' => 'Indiquez le nom de la catégorie.',
            'name.string' => "Le nom de la catégorie n'est pas valide.",
            'name.min' => 'Le nom de la catégorie doit contenir au moins 2 caractères.',
            'name.max' => 'Le nom de la catégorie ne peut pas dépasser 100 caractères.',
            'description.string' => "La description n'est pas valide.",
            'description.max' => 'La description ne peut pas dépasser 500 caractères.',
            'parent_id.string' => "La catégorie parente n'est pas valide.",
            'parent_id.size' => "La catégorie parente n'est pas valide.",
        ];
    }

    private function currentCategory(): ?Category
    {
        $category = $this->route('category');

        return $category instanceof Category ? $category : null;
    }

    /**
     * Le parent visé : celui de la saisie, sinon celui que la catégorie a déjà.
     */
    private function effectiveParentId(): ?string
    {
        $parentId = $this->has('parent_id') ? $this->input('parent_id') : $this->currentCategory()?->parent_id;

        return is_string($parentId) ? $parentId : null;
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