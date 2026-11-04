<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use InteractsWithTenants;

    private const URL = '/api/v1/suppliers';

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function make(Tenant $tenant, string $name, array $attributes = []): Supplier
    {
        $supplier = (new Supplier)->forceFill(array_merge(
            ['tenant_id' => $tenant->id, 'name' => $name],
            $attributes,
        ));
        $supplier->save();

        return $supplier;
    }

    // ------------------------------------------------------ Isolation et droits

    public function test_each_company_only_sees_and_touches_its_own_suppliers(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $foreign = $this->make($tenantB, 'Riz Afrique');

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URL."/{$foreign->id}")->assertNotFound();
        $this->putJson(self::URL."/{$foreign->id}", ['name' => 'Piraté'])->assertNotFound();
        $this->deleteJson(self::URL."/{$foreign->id}")->assertNotFound();

        $this->assertSame('Riz Afrique', $foreign->fresh()->name);
    }

    public function test_catalog_managers_can_write_and_other_roles_cannot(): void
    {
        [$tenant] = $this->createTenant();
        $supplier = $this->make($tenant, 'Riz Afrique');
        $denied = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les fournisseurs.';

        foreach ([TenantRole::Admin, TenantRole::StockManager] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);
            $this->postJson(self::URL, ['name' => 'Fournisseur '.$role->value])->assertCreated();
        }

        foreach ([TenantRole::Seller, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', false);
            $this->postJson(self::URL, ['name' => 'Interdit'])->assertForbidden()->assertJsonPath('message', $denied);
            $this->putJson(self::URL."/{$supplier->id}", ['name' => 'Autre'])->assertForbidden();
            $this->deleteJson(self::URL."/{$supplier->id}")->assertForbidden();
            $this->postJson(self::URL."/{$supplier->id}/deactivate")->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- Création

    public function test_create_and_update_a_supplier_with_normalized_data(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $id = $this->postJson(self::URL, [
            'name' => '  Riz   Afrique  SARL ',
            'contact_name' => 'Moussa Diallo',
            'phone' => '+229 01 02 03 04',
            'email' => ' Contact@RizAfrique.com ',
            'address' => 'Zone industrielle',
            'city' => 'Cotonou',
            'notes' => 'Livre le mardi.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Riz Afrique SARL')
            ->assertJsonPath('data.email', 'contact@rizafrique.com')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.can.update', true)
            ->json('data.id');

        $this->putJson(self::URL."/{$id}", ['name' => 'Riz Afrique', 'contact_name' => null, 'city' => 'Porto-Novo'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Riz Afrique')
            ->assertJsonPath('data.contact_name', null)
            ->assertJsonPath('data.city', 'Porto-Novo')
            ->assertJsonPath('data.phone', '+229 01 02 03 04');
    }

    public function test_validation_messages_are_explicit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Indiquez le nom du fournisseur.');

        $this->postJson(self::URL, [
            'name' => 'Riz Afrique',
            'email' => 'pas-un-email',
            'phone' => 'abc',
            'notes' => str_repeat('a', 501),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', "Cette adresse email n'est pas valide. Exemple : contact@fournisseur.com")
            ->assertJsonPath('errors.phone.0', "Ce numéro de téléphone n'est pas valide. Exemple : +229 01 02 03 04")
            ->assertJsonPath('errors.notes.0', 'Les notes ne peuvent pas dépasser 500 caractères.');
    }

    public function test_names_are_unique_within_a_company_only(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');
        $existing = $this->make($tenantA, 'Riz Afrique SARL');

        $this->asMember($ownerA, $tenantA);

        $this->postJson(self::URL, ['name' => ' riz   AFRIQUE sarl '])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.name.0',
                'Un fournisseur nommé « riz AFRIQUE sarl » existe déjà. Choisissez un autre nom.',
            );

        $this->putJson(self::URL."/{$existing->id}", ['name' => 'Riz Afrique SARL', 'city' => 'Cotonou'])->assertOk();

        $this->asMember($ownerB, $tenantB);
        $this->postJson(self::URL, ['name' => 'Riz Afrique SARL'])->assertCreated();
    }

    // ------------------------------------------------- Recherche, filtres, pages

    public function test_search_and_status_filters(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->make($tenant, 'Alpha SARL', ['contact_name' => 'Moussa Diallo', 'city' => 'Cotonou', 'phone' => '+229 01 02 03 04']);
        $beta = $this->make($tenant, 'Beta Import', ['contact_name' => 'Awa Traoré', 'city' => 'Lomé']);

        $this->asMember($owner, $tenant);

        $this->assertSame(['Alpha SARL'], $this->getJson(self::URL.'?search=diallo')->json('data.*.name'));
        $this->assertSame(['Alpha SARL'], $this->getJson(self::URL.'?search=cotonou')->json('data.*.name'));
        $this->assertSame(['Alpha SARL'], $this->getJson(self::URL.'?search=%2B229')->json('data.*.name'));
        $this->assertSame(['Beta Import'], $this->getJson(self::URL.'?search=beta')->json('data.*.name'));

        $this->postJson(self::URL."/{$beta->id}/deactivate")->assertOk();

        $this->assertSame(['Beta Import'], $this->getJson(self::URL.'?status=inactive')->json('data.*.name'));
        $this->assertSame(['Alpha SARL'], $this->getJson(self::URL.'?status=active')->json('data.*.name'));
    }

    public function test_the_list_is_paginated(): void
    {
        [$tenant, $owner] = $this->createTenant();

        for ($i = 1; $i <= 25; $i++) {
            $this->make($tenant, sprintf('Fournisseur %02d', $i));
        }

        $this->asMember($owner, $tenant);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2);

        $page = $this->getJson(self::URL.'?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $this->assertSame('Fournisseur 11', $page->json('data.0.name'));

        $this->getJson(self::URL.'?per_page=500')->assertJsonPath('meta.per_page', 100);
    }

    // ------------------------------------------------- Activation, suppression

    public function test_a_supplier_can_be_deactivated_reactivated_and_deleted(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $id = $this->make($tenant, Str::title('Riz Afrique'))->id;

        $this->asMember($owner, $tenant);

        $this->postJson(self::URL."/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson(self::URL."/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->deleteJson(self::URL."/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('suppliers', ['id' => $id]);
    }

    // ----------------------------------------------------------- Lecture seule

    public function test_an_expired_company_can_read_but_not_write(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->make($tenant, 'Riz Afrique');
        $tenant->forceFill(['status' => TenantStatus::Expired])->save();

        $this->asMember($owner, $tenant);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.can.create', false)
            ->assertJsonPath('data.0.can.update', false);

        $this->postJson(self::URL, ['name' => 'Interdit'])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_read_only');
    }
}