<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class WarehousePolicy
{
    private const DENIED = 'Seuls le propriétaire et les administrateurs peuvent gérer les entrepôts.';

    public function viewAny(User $user): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function create(User $user): Response
    {
        return $this->manage();
    }

    public function update(User $user, Warehouse $warehouse): Response
    {
        return $this->manage();
    }

    public function delete(User $user, Warehouse $warehouse): Response
    {
        return $this->manage();
    }

    public function activate(User $user, Warehouse $warehouse): Response
    {
        return $this->manage();
    }

    public function deactivate(User $user, Warehouse $warehouse): Response
    {
        return $this->manage();
    }

    public function setMain(User $user, Warehouse $warehouse): Response
    {
        return $this->manage();
    }

    private function manage(): Response
    {
        return app(CurrentTenant::class)->role()->canManageStructure()
            ? Response::allow()
            : Response::deny(self::DENIED);
    }
}