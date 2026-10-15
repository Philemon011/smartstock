<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class CategoryPolicy
{
    private const DENIED = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les catégories.';

    public function viewAny(User $user): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function view(User $user, Category $category): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function create(User $user): Response
    {
        return $this->manage();
    }

    public function update(User $user, Category $category): Response
    {
        return $this->manage();
    }

    public function delete(User $user, Category $category): Response
    {
        return $this->manage();
    }

    public function activate(User $user, Category $category): Response
    {
        return $this->manage();
    }

    public function deactivate(User $user, Category $category): Response
    {
        return $this->manage();
    }

    private function manage(): Response
    {
        return app(CurrentTenant::class)->role()->canManageCatalog()
            ? Response::allow()
            : Response::deny(self::DENIED);
    }
}