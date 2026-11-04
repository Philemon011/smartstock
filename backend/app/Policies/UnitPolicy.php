<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class UnitPolicy
{
    private const DENIED = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les unités de mesure.';

    public function viewAny(User $user): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function view(User $user, Unit $unit): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function create(User $user): Response
    {
        return $this->manage();
    }

    public function update(User $user, Unit $unit): Response
    {
        return $this->manage();
    }

    public function delete(User $user, Unit $unit): Response
    {
        return $this->manage();
    }

    public function activate(User $user, Unit $unit): Response
    {
        return $this->manage();
    }

    public function deactivate(User $user, Unit $unit): Response
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