<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use InteractsWithTenants;

    private const URL = '/api/v1/customers';

    private function seedCustomer(Tenant $tenant, string $name, array $attributes = []): Customer
    {
        $customer = (new Customer)->forceFill(array_merge(['tenant_id' => $tenant->id, 'name' => $name], $attributes));
        $customer->save();

        return $customer;
    }

    // ------------------------------------------------------ Droits, isolation

    public function test_each_company_only_sees_and_touches_its_own_customers(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerB, $tenantB);
        $id = $this->postJson(self::URL, ['name' => 'Kofi Mensah'])->assertCreated()->json('data.id');

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL."/{$id}")->assertNotFound();
        $this->putJson(self::URL."/{$id}", ['name' => 'Pirate'])->assertNotFound();
        $this->deleteJson(self::URL."/{$id}")->assertNotFound();
    }

    public function test_sellers_write_but_stock_managers_and_viewers_only_read(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $id = $this->postJson(self::URL, ['name' => 'Awa Sow'])->assertCreated()->json('data.id');

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);
        $this->postJson(self::URL, ['name' => 'Client du vendeur'])->assertCreated();
        $this->putJson(self::URL."/{$id}", ['name' => 'Awa Sow-Diallo'])->assertOk();

        foreach ([TenantRole::StockManager, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', false);
            $this->getJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data.can.update', false);
            $this->postJson(self::URL, ['name' => 'Interdit'])
                ->assertForbidden()
                ->assertJsonPath('message', 'Seuls le propriétaire, les administrateurs et les vendeurs peuvent gérer les clients.');
            $this->putJson(self::URL."/{$id}", ['name' => 'Interdit'])->assertForbidden();
        }
    }

    public function test_customers_require_a_session(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    // ----------------------------------------------------------- Création

    public function test_create_and_update_normalize_the_data(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $response = $this->postJson(self::URL, [
            'name' => '  Kofi    Mensah ',
            'phone' => '+229 01 02 03 04',
            'email' => '  KOFI@Mail.COM ',
            'city' => ' Cotonou ',
            'notes' => 'Client fidèle',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.name', 'Kofi Mensah')
            ->assertJsonPath('data.email', 'kofi@mail.com')
            ->assertJsonPath('data.city', 'Cotonou')
            ->assertJsonPath('data.credit_limit', null)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.can.update', true);

        $id = $response->json('data.id');

        $this->putJson(self::URL."/{$id}", ['name' => 'Kofi Mensah', 'email' => '', 'phone' => ''])
            ->assertOk()
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.phone', null);
    }

    public function test_validation_messages_are_explicit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name' => 'Indiquez le nom du client.']);

        $this->postJson(self::URL, ['name' => 'A'])
            ->assertJsonValidationErrors(['name' => 'Le nom du client doit contenir au moins 2 caractères.']);

        $this->postJson(self::URL, ['name' => 'Kofi', 'phone' => 'abc'])
            ->assertJsonValidationErrors(['phone' => "Ce numéro de téléphone n'est pas valide. Exemple : +229 01 02 03 04"]);

        $this->postJson(self::URL, ['name' => 'Kofi', 'email' => 'pas-un-email'])
            ->assertJsonValidationErrors(['email' => "Cette adresse email n'est pas valide. Exemple : client@exemple.com"]);

        $this->postJson(self::URL, ['name' => 'Kofi', 'notes' => str_repeat('a', 501)])
            ->assertJsonValidationErrors(['notes' => 'Les notes ne peuvent pas dépasser 500 caractères.']);
    }

    public function test_two_customers_may_share_the_same_name(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, ['name' => 'Kofi'])->assertCreated();
        $this->postJson(self::URL, ['name' => 'Kofi'])->assertCreated();

        $this->getJson(self::URL)->assertJsonPath('meta.total', 2);
    }

    // ------------------------------------------------------ Plafond de crédit

    public function test_the_credit_limit_follows_the_decimals_of_the_currency(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $tenant->forceFill(['currency' => 'XOF'])->save();

        $this->postJson(self::URL, ['name' => 'Kofi', 'credit_limit' => '150000'])
            ->assertCreated()
            ->assertJsonPath('data.credit_limit', '150000');

        $this->postJson(self::URL, ['name' => 'Awa', 'credit_limit' => '0'])
            ->assertCreated()
            ->assertJsonPath('data.credit_limit', '0');

        $this->postJson(self::URL, ['name' => 'Moussa', 'credit_limit' => null])
            ->assertCreated()
            ->assertJsonPath('data.credit_limit', null);

        foreach (['1000.5', '-5', 'abc'] as $invalid) {
            $this->postJson(self::URL, ['name' => 'Invalide', 'credit_limit' => $invalid])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['credit_limit' => 'Le plafond de crédit est invalide : saisissez un montant entier, sans décimales.']);
        }

        $tenant->forceFill(['currency' => 'EUR'])->save();

        $this->postJson(self::URL, ['name' => 'Euro', 'credit_limit' => '10.55'])
            ->assertCreated()
            ->assertJsonPath('data.credit_limit', '10.55');

        $this->postJson(self::URL, ['name' => 'Euro 2', 'credit_limit' => '10.555'])
            ->assertJsonValidationErrors(['credit_limit' => 'Le plafond de crédit est invalide : saisissez un montant avec au plus 2 décimales.']);
    }

    public function test_only_owners_and_admins_can_set_the_credit_limit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $tenant->forceFill(['currency' => 'XOF'])->save();

        $id = $this->postJson(self::URL, ['name' => 'Kofi', 'credit_limit' => '50000'])
            ->assertCreated()
            ->json('data.id');

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);

        $this->getJson(self::URL."/{$id}")->assertJsonPath('data.can.set_credit_limit', false);

        $this->postJson(self::URL, ['name' => 'Nouveau', 'credit_limit' => '5000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credit_limit' => 'Seuls le propriétaire et les administrateurs peuvent définir le plafond de crédit.']);

        $this->postJson(self::URL, ['name' => 'Nouveau', 'credit_limit' => null])->assertCreated();

        $this->putJson(self::URL."/{$id}", ['name' => 'Kofi M.', 'credit_limit' => '50000'])
            ->assertOk()
            ->assertJsonPath('data.credit_limit', '50000');

        $this->putJson(self::URL."/{$id}", ['name' => 'Kofi M.', 'credit_limit' => '900000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credit_limit']);

        $this->putJson(self::URL."/{$id}", ['name' => 'Kofi M.'])->assertOk();

        $this->asMember($this->addMember($tenant, TenantRole::Admin), $tenant);

        $this->putJson(self::URL."/{$id}", ['name' => 'Kofi M.', 'credit_limit' => '900000'])
            ->assertOk()
            ->assertJsonPath('data.credit_limit', '900000')
            ->assertJsonPath('data.can.set_credit_limit', true);
    }

    // ------------------------------------------------- Recherche, pagination

    public function test_search_and_status_filters(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->seedCustomer($tenant, 'Awa Sow', ['phone' => '+229 01 02 03 04', 'city' => 'Cotonou']);
        $this->seedCustomer($tenant, 'Kofi Mensah', ['email' => 'kofi@mail.com', 'city' => 'Porto-Novo']);
        $this->seedCustomer($tenant, 'Ancien client', ['is_active' => false]);

        $names = fn (string $query) => $this->getJson(self::URL.$query)->assertOk()->json('data.*.name');

        $this->assertSame(['Ancien client', 'Awa Sow', 'Kofi Mensah'], $names(''));
        $this->assertSame(['Awa Sow'], $names('?search=awa'));
        $this->assertSame(['Awa Sow'], $names('?search=01 02'));
        $this->assertSame(['Kofi Mensah'], $names('?search=kofi@mail'));
        $this->assertSame(['Kofi Mensah'], $names('?search=porto'));
        $this->assertSame(['Awa Sow', 'Kofi Mensah'], $names('?status=active'));
        $this->assertSame(['Ancien client'], $names('?status=inactive'));
    }

    public function test_the_list_is_paginated(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        for ($i = 1; $i <= 25; $i++) {
            $this->seedCustomer($tenant, sprintf('Client %02d', $i));
        }

        $page = $this->getJson(self::URL.'?per_page=10&page=3')->assertOk();

        $this->assertCount(5, $page->json('data'));
        $page->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.can.create', true);

        $this->assertSame('Client 21', $page->json('data.0.name'));
    }

    // ------------------------------------------------ Activation, suppression

    public function test_a_customer_can_be_deactivated_reactivated_and_deleted_by_admins(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $id = $this->postJson(self::URL, ['name' => 'Kofi'])->assertCreated()->json('data.id');

        $this->postJson(self::URL."/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson(self::URL."/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);

        $this->deleteJson(self::URL."/{$id}")->assertNoContent();
        $this->getJson(self::URL."/{$id}")->assertNotFound();
    }

    public function test_sellers_cannot_delete_or_deactivate(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $id = $this->postJson(self::URL, ['name' => 'Kofi'])->assertCreated()->json('data.id');

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);

        $message = 'Seuls le propriétaire et les administrateurs peuvent supprimer ou désactiver un client.';

        $this->deleteJson(self::URL."/{$id}")->assertForbidden()->assertJsonPath('message', $message);
        $this->postJson(self::URL."/{$id}/deactivate")->assertForbidden()->assertJsonPath('message', $message);
        $this->postJson(self::URL."/{$id}/activate")->assertForbidden();
    }

    public function test_an_expired_company_can_read_but_not_write(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $this->postJson(self::URL, ['name' => 'Kofi'])->assertCreated();

        $tenant->forceFill(['status' => 'expired'])->save();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', false);
        $this->postJson(self::URL, ['name' => 'Interdit'])->assertForbidden();
    }

    // ---------------------------------------------------- Garde-fou PostgreSQL

    public function test_the_database_refuses_a_negative_credit_limit(): void
    {
        [$tenant] = $this->createTenant();

        $this->expectException(QueryException::class);

        $this->seedCustomer($tenant, 'Kofi', ['credit_limit' => '-1']);
    }
}