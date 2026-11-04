<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class SupplierPolicy
{
    private const DENIED = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les fournisseurs.';

    public function viewAny(User $user): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function create(User $user): Response
    {
        return $this->manage();
    }

    public function update(User $user, Supplier $supplier): Response
    {
        return $this->manage();
    }

    public function delete(User $user, Supplier $supplier): Response
    {
        return $this->manage();
    }

    public function activate(User $user, Supplier $supplier): Response
    {
        return $this->manage();
    }

    public function deactivate(User $user, Supplier $supplier): Response
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