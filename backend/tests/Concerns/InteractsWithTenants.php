<?php

namespace Tests\Concerns;

use App\Actions\Tenants\RegisterTenant;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

trait InteractsWithTenants
{
    /**
     * @return array{0: Tenant, 1: User}
     */
    protected function createTenant(string $suffix = 'a'): array
    {
        $result = app(RegisterTenant::class)->handle([
            'company_name' => "Entreprise {$suffix}",
            'country' => 'BJ',
            'currency' => 'XOF',
            'name' => "Propriétaire {$suffix}",
            'email' => "proprietaire-{$suffix}@example.com",
            'password' => 'mot-de-passe-test-123',
        ]);

        return [$result['tenant'], $result['user']];
    }

    protected function addMember(Tenant $tenant, TenantRole $role): User
    {
        $user = User::create([
            'name' => 'Membre '.$role->value,
            'email' => $role->value.'-'.Str::lower(Str::random(6)).'@example.com',
            'password' => 'mot-de-passe-test-123',
        ]);

        $tenant->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    protected function asMember(User $user, Tenant $tenant): static
    {
        // Les requêtes d'un même test partagent le stockage de session : on repart d'une session vierge.
        $this->flushSession();
        Auth::forgetGuards();
        Auth::shouldUse('web');

        return $this->actingAs($user)->withHeaders([
            'X-Tenant-Id' => $tenant->id,
            'Referer' => 'http://localhost:3000',
        ]);
    }
}