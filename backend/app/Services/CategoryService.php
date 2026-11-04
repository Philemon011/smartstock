<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Article;
use App\Models\Category;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Category
    {
        return $this->atomically(function () use ($data): Category {
            $parentId = $data['parent_id'] ?? null;

            if ($parentId !== null) {
                $this->assertValidParent((string) $parentId, null);
            }

            $category = new Category($data);
            $category->save();

            return $category->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Category $category, array $data): Category
    {
        return $this->atomically(function () use ($category, $data): Category {
            $category->refresh();

            $moves = array_key_exists('parent_id', $data) && $data['parent_id'] !== $category->parent_id;

            if ($moves && $data['parent_id'] !== null) {
                $this->assertValidParent((string) $data['parent_id'], $category);
            }

            $category->fill($data)->save();

            return $category->refresh();
        });
    }

    /**
     * Désactiver une catégorie principale désactive aussi ses sous-catégories.
     */
    public function deactivate(Category $category): Category
    {
        return $this->atomically(function () use ($category): Category {
            $category->refresh();
            $category->forceFill(['is_active' => false])->save();

            if ($category->isRoot()) {
                Category::query()->where('parent_id', $category->id)->update(['is_active' => false]);
            }

            return $category;
        });
    }

    /**
     * Réactiver une catégorie principale ne réactive pas ses sous-catégories : on les réactive une par une.
     */
    public function activate(Category $category): Category
    {
        return $this->atomically(function () use ($category): Category {
            $category->refresh();

            if (! $category->isRoot()) {
                $parent = Category::query()->find($category->parent_id);

                if ($parent === null || ! $parent->is_active) {
                    throw new BusinessRuleException(
                        'Réactivez d\'abord la catégorie parente « '.($parent?->name ?? '?').' ».',
                        'parent_inactive',
                    );
                }
            }

            $category->forceFill(['is_active' => true])->save();

            return $category;
        });
    }

    public function delete(Category $category): void
    {
        $this->atomically(function () use ($category): void {
            $category->refresh();

            if ($category->children()->exists()) {
                throw new BusinessRuleException(
                    'Cette catégorie contient des sous-catégories. Supprimez-les ou déplacez-les d\'abord.',
                    'category_has_children',
                );
            }

            if ($this->isUsed($category)) {
                throw new BusinessRuleException(
                    'Des articles utilisent cette catégorie. Désactivez-la plutôt que de la supprimer.',
                    'category_used',
                );
            }

            $category->delete();
        });
    }

    /**
     * Une catégorie est « utilisée » si des articles y sont rattachés.
     * Le module Articles complétera cette règle quand ces données existeront.
     */
    private function isUsed(Category $category): bool
    {
        return Article::query()->where('category_id', $category->id)->exists();
    }

    private function assertValidParent(string $parentId, ?Category $self): void
    {
        $parent = Category::query()->lockForUpdate()->find($parentId);

        if ($parent === null) {
            throw new BusinessRuleException('La catégorie parente est introuvable.', 'parent_not_found');
        }

        if ($self !== null && $parent->id === $self->id) {
            throw new BusinessRuleException('Une catégorie ne peut pas être sa propre parente.', 'own_parent');
        }

        if (! $parent->isRoot()) {
            throw new BusinessRuleException(
                'Une sous-catégorie ne peut pas avoir de sous-catégories.',
                'parent_not_root',
            );
        }

        if (! $parent->is_active) {
            throw new BusinessRuleException(
                'La catégorie parente est désactivée. Réactivez-la d\'abord.',
                'parent_inactive',
            );
        }

        if ($self !== null && $self->children()->exists()) {
            throw new BusinessRuleException(
                'Cette catégorie contient des sous-catégories : elle ne peut pas devenir une sous-catégorie.',
                'category_has_children',
            );
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function atomically(Closure $callback): mixed
    {
        $tenantId = app(CurrentTenant::class)->id();

        return DB::transaction(function () use ($callback, $tenantId) {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            try {
                return $callback();
            } catch (UniqueConstraintViolationException) {
                // Deux personnes ont saisi le même nom au même endroit au même instant.
                throw ValidationException::withMessages([
                    'name' => 'Une catégorie portant ce nom existe déjà à cet endroit. Choisissez un autre nom.',
                ]);
            }
        });
    }
}
