<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class TenantPolicy
{
    private const DENIED = "Seuls le propriétaire et les administrateurs peuvent modifier le profil de l'entreprise.";

    public function view(User $user, Tenant $tenant): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function update(User $user, Tenant $tenant): Response
    {
        return app(CurrentTenant::class)->role()->canManageStructure()
            ? Response::allow()
            : Response::deny(self::DENIED);
    }
}