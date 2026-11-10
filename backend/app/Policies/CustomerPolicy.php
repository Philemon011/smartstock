<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class CustomerPolicy
{
    private const MANAGE = 'Seuls le propriétaire, les administrateurs et les vendeurs peuvent gérer les clients.';

    private const ADMIN = 'Seuls le propriétaire et les administrateurs peuvent supprimer ou désactiver un client.';

    public function viewAny(User $user): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function view(User $user, Customer $customer): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function create(User $user): Response
    {
        return $this->manage();
    }

    public function update(User $user, Customer $customer): Response
    {
        return $this->manage();
    }

    public function delete(User $user, Customer $customer): Response
    {
        return $this->admin();
    }

    public function activate(User $user, Customer $customer): Response
    {
        return $this->admin();
    }

    public function deactivate(User $user, Customer $customer): Response
    {
        return $this->admin();
    }

    private function manage(): Response
    {
        return app(CurrentTenant::class)->role()->canSell()
            ? Response::allow()
            : Response::deny(self::MANAGE);
    }

    private function admin(): Response
    {
        return app(CurrentTenant::class)->role()->canManageStructure()
            ? Response::allow()
            : Response::deny(self::ADMIN);
    }
}