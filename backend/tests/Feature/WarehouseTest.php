<?php

namespace Tests\Feature;

use App\Actions\Tenants\RegisterTenant;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Tests\TestCase;
use Illuminate\Support\Facades\Auth;

class WarehouseTest extends TestCase
{
    private const URL = '/api/v1/warehouses';

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function createTenant(string $suffix = 'a'): array
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

    private function addMember(Tenant $tenant, TenantRole $role): User
    {
        $user = User::create([
            'name' => 'Membre '.$role->value,
            'email' => $role->value.'-'.Str::lower(Str::random(6)).'@example.com',
            'password' => 'mot-de-passe-test-123',
        ]);

        $tenant->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function asMember(User $user, Tenant $tenant): static
    {
        // Les requêtes d'un même test partagent le stockage de session : on repart d'une session vierge pour chaque personne.
        $this->flushSession();
        Auth::forgetGuards();
        Auth::shouldUse('web');

        return $this->actingAs($user)->withHeaders([
            'X-Tenant-Id' => $tenant->id,
            'Referer' => 'http://localhost:3000',
        ]);
    }

    private function mainWarehouse(Tenant $tenant): Warehouse
    {
        return Warehouse::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_main', true)
            ->sole();
    }

    // ------------------------------------------------------------ Inscription

    public function test_registration_creates_a_default_main_warehouse(): void
    {
        [$tenant] = $this->createTenant();

        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

        $this->assertSame('Entrepôt principal', $warehouse->name);
        $this->assertTrue($warehouse->is_main);
        $this->assertTrue($warehouse->is_active);
    }

    // -------------------------------------------------------------- Isolation

    public function test_each_company_only_sees_and_touches_its_own_warehouses(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $foreign = $this->mainWarehouse($tenantB);

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->mainWarehouse($tenantA)->id);

        $this->getJson(self::URL."/{$foreign->id}")->assertNotFound();
        $this->putJson(self::URL."/{$foreign->id}", ['name' => 'Piraté'])->assertNotFound();
        $this->deleteJson(self::URL."/{$foreign->id}")->assertNotFound();
        $this->postJson(self::URL."/{$foreign->id}/deactivate")->assertNotFound();

        $this->assertSame('Entrepôt principal', $foreign->fresh()->name);
    }

    public function test_the_requested_company_must_belong_to_the_user(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');

        $this->actingAs($ownerA)
            ->withHeaders(['X-Tenant-Id' => $tenantB->id, 'Referer' => 'http://localhost:3000'])
            ->getJson(self::URL)
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_forbidden');
    }

    public function test_a_user_in_several_companies_must_choose_one(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $tenantB->memberships()->create(['user_id' => $ownerA->id, 'role' => TenantRole::Admin]);

        $this->actingAs($ownerA)
            ->withHeaders(['Referer' => 'http://localhost:3000'])
            ->getJson(self::URL)
            ->assertStatus(400)
            ->assertJsonPath('code', 'tenant_required');

        $this->asMember($ownerA, $tenantB)->getJson(self::URL)->assertOk();
    }

    // ------------------------------------------------------------------ Droits

    public function test_only_owner_and_admin_can_manage_warehouses(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $warehouse = $this->mainWarehouse($tenant);
        $denied = 'Seuls le propriétaire et les administrateurs peuvent gérer les entrepôts.';

        foreach ([TenantRole::StockManager, TenantRole::Seller, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)->assertOk();
            $this->postJson(self::URL, ['name' => 'Dépôt Nord'])->assertForbidden()->assertJsonPath('message', $denied);
            $this->putJson(self::URL."/{$warehouse->id}", ['name' => 'Autre nom'])->assertForbidden();
            $this->deleteJson(self::URL."/{$warehouse->id}")->assertForbidden();
            $this->postJson(self::URL."/{$warehouse->id}/deactivate")->assertForbidden();
            $this->postJson(self::URL."/{$warehouse->id}/set-main")->assertForbidden();
        }

        $this->asMember($this->addMember($tenant, TenantRole::Admin), $tenant);
        $this->postJson(self::URL, ['name' => 'Dépôt Nord'])->assertCreated();
    }

    // -------------------------------------------------------------------- CRUD

    public function test_create_update_and_list_warehouses(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $created = $this->postJson(self::URL, [
            'name' => '  Dépôt   Nord ',
            'code' => ' dep-01 ',
            'address' => 'Quartier Haie Vive',
            'city' => 'Cotonou',
            'phone' => '+229 01 02 03 04',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dépôt Nord')
            ->assertJsonPath('data.code', 'DEP-01')
            ->assertJsonPath('data.is_main', false)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.can.update', true);

        $id = $created->json('data.id');

        $this->putJson(self::URL."/{$id}", ['name' => 'Dépôt Nord 2', 'code' => null, 'city' => 'Porto-Novo'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dépôt Nord 2')
            ->assertJsonPath('data.code', null)
            ->assertJsonPath('data.city', 'Porto-Novo')
            ->assertJsonPath('data.address', 'Quartier Haie Vive');

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_main', true)
            ->assertJsonPath('meta.can.create', true);

        $this->getJson(self::URL.'?search=porto')->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?status=inactive')->assertJsonCount(0, 'data');
    }

    public function test_names_and_codes_are_unique_within_a_company_only(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerA, $tenantA);
        $id = $this->postJson(self::URL, ['name' => 'Dépôt Nord', 'code' => 'DEP-01'])->assertCreated()->json('data.id');

        $this->postJson(self::URL, ['name' => ' depot  NORD '])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Un entrepôt nommé « depot NORD » existe déjà. Choisissez un autre nom.');

        $this->postJson(self::URL, ['name' => 'Autre dépôt', 'code' => 'dep-01'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Ce code est déjà utilisé par un autre entrepôt.');

        $this->putJson(self::URL."/{$id}", ['name' => 'Dépôt Nord', 'code' => 'DEP-01'])->assertOk();

        $this->asMember($ownerB, $tenantB);
        $this->postJson(self::URL, ['name' => 'Dépôt Nord', 'code' => 'DEP-01'])->assertCreated();
    }

    public function test_validation_messages_are_explicit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', "Indiquez le nom de l'entrepôt.");

        $this->postJson(self::URL, ['name' => 'Dépôt', 'code' => 'dép ôt!', 'phone' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Le code ne peut contenir que des lettres, des chiffres, des points, des tirets et des tirets bas.')
            ->assertJsonPath('errors.phone.0', 'Ce numéro de téléphone n\'est pas valide. Exemple : +229 01 02 03 04');
    }

    // -------------------------------------------------- Principal et activation

    public function test_main_warehouse_can_be_switched_and_stays_unique(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $original = $this->mainWarehouse($tenant);

        $otherId = $this->postJson(self::URL, ['name' => 'Boutique'])->json('data.id');

        $this->postJson(self::URL."/{$otherId}/set-main")
            ->assertOk()
            ->assertJsonPath('data.is_main', true);

        $this->assertFalse($original->fresh()->is_main);
        $this->assertSame(1, Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_main', true)->count());
    }

    public function test_the_main_warehouse_cannot_be_deactivated_or_deleted(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $main = $this->mainWarehouse($tenant);

        $this->postJson(self::URL."/{$main->id}/deactivate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'main_warehouse')
            ->assertJsonPath('message', 'Définissez un autre entrepôt principal avant de désactiver celui-ci.');

        $this->deleteJson(self::URL."/{$main->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'main_warehouse');

        $this->assertTrue($main->fresh()->is_active);
    }

    public function test_a_secondary_warehouse_can_be_deactivated_reactivated_and_deleted(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $id = $this->postJson(self::URL, ['name' => 'Boutique'])->json('data.id');

        $this->postJson(self::URL."/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        $this->postJson(self::URL."/{$id}/set-main")
            ->assertStatus(409)
            ->assertJsonPath('code', 'warehouse_inactive');

        $this->postJson(self::URL."/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);

        $this->deleteJson(self::URL."/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('warehouses', ['id' => $id]);
    }

    // ----------------------------------------------------------- Lecture seule

    public function test_an_expired_company_can_read_but_not_write(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $tenant->forceFill(['status' => TenantStatus::Expired])->save();
        $this->asMember($owner, $tenant);

                $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.can.create', false)
            ->assertJsonPath('data.0.can.update', false);

        $this->postJson(self::URL, ['name' => 'Dépôt Nord'])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_read_only')
            ->assertJsonPath(
                'message',
                'Votre essai gratuit est terminé. Vos données restent consultables, mais vous ne pouvez plus les modifier.',
            );
    }

    public function test_a_trial_past_its_end_date_is_read_only_even_if_the_status_was_not_updated(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $tenant->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, ['name' => 'Dépôt Nord'])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_read_only');
    }

    public function test_a_suspended_company_gets_its_own_message(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, ['name' => 'Dépôt Nord'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Cet espace est suspendu. Contactez le support pour le réactiver.');
    }

    // ------------------------------------------------------ Garde-fous de la base

    public function test_the_database_refuses_two_main_warehouses_in_one_company(): void
    {
        [$tenant] = $this->createTenant();

        $this->expectException(UniqueConstraintViolationException::class);

        (new Warehouse)->forceFill([
            'tenant_id' => $tenant->id,
            'name' => 'Deuxième principal',
            'is_main' => true,
        ])->save();
    }

    public function test_the_database_refuses_an_inactive_main_warehouse(): void
    {
        [$tenant] = $this->createTenant();

        $this->expectException(QueryException::class);

        $this->mainWarehouse($tenant)->forceFill(['is_active' => false])->save();
    }
}