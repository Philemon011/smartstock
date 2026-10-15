<?php

namespace App\Support\Tenancy;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantUser;
use LogicException;

/**
 * L'entreprise sur laquelle la requête en cours travaille, et le rôle de la personne dedans.
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    private ?TenantUser $membership = null;

    public function set(Tenant $tenant, TenantUser $membership): void
    {
        $this->tenant = $tenant;
        $this->membership = $membership;
    }

    public function isSet(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new LogicException('Aucune entreprise active dans cette requête.');
    }

    public function membership(): TenantUser
    {
        return $this->membership ?? throw new LogicException('Aucune entreprise active dans cette requête.');
    }

    public function id(): string
    {
        return (string) $this->tenant()->getKey();
    }

    public function role(): TenantRole
    {
        return $this->membership()->role;
    }
}