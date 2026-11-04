<?php

namespace App\Actions\Tenants;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class RegisterTenant
{
    /**
     * @param  array{company_name: string, country: string, currency: string, name: string, email: string, password: string}  $data
     * @return array{user: User, tenant: Tenant}
     */
    public function handle(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $tenant = Tenant::create([
                'name' => $data['company_name'],
                'country' => $data['country'],
                'currency' => $data['currency'],
                'timezone' => config("tenants.countries.{$data['country']}.timezone", 'UTC'),
            ]);

            $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => TenantRole::Owner,
            ]);

            // Dès l'inscription, l'entreprise a un lieu de stockage : elle n'est jamais bloquée.
            (new Warehouse)->forceFill([
                'tenant_id' => $tenant->id,
                'name' => 'Entrepôt principal',
                'is_main' => true,
            ])->save();

            // Les unités de mesure courantes sont prêtes dès l'inscription.
            app(CreateDefaultUnits::class)->handle($tenant);

            return ['user' => $user, 'tenant' => $tenant];
        });
    }
}