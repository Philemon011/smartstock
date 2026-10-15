<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use InteractsWithTenants;

    private const URL = '/api/v1/categories';

    private function make(Tenant $tenant, string $name, ?string $parentId = null): Category
    {
        $category = (new Category)->forceFill([
            'tenant_id' => $tenant->id,
            'parent_id' => $parentId,
            'name' => $name,
        ]);
        $category->save();

        return $category;
    }

    // ------------------------------------------------------ Isolation et droits

    public function test_each_company_only_sees_and_touches_its_own_categories(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $foreign = $this->make($tenantB, 'Boissons');

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URL."/{$foreign->id}")->assertNotFound();
        $this->putJson(self::URL."/{$foreign->id}", ['name' => 'Piratée'])->assertNotFound();
        $this->deleteJson(self::URL."/{$foreign->id}")->assertNotFound();

        $this->assertSame('Boissons', $foreign->fresh()->name);
    }

    public function test_catalog_managers_can_write_and_other_roles_cannot(): void
    {
        [$tenant] = $this->createTenant();
        $denied = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les catégories.';
        $category = $this->make($tenant, 'Boissons');

        foreach ([TenantRole::Admin, TenantRole::StockManager] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);
            $this->postJson(self::URL, ['name' => 'Catégorie '.$role->value])->assertCreated();
        }

        foreach ([TenantRole::Seller, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', false);
            $this->postJson(self::URL, ['name' => 'Interdite'])->assertForbidden()->assertJsonPath('message', $denied);
            $this->putJson(self::URL."/{$category->id}", ['name' => 'Autre'])->assertForbidden();
            $this->deleteJson(self::URL."/{$category->id}")->assertForbidden();
            $this->postJson(self::URL."/{$category->id}/deactivate")->assertForbidden();
        }
    }

    // ------------------------------------------------------------ Création, arbre

    public function test_the_list_is_returned_as_a_tree(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $boissons = $this->make($tenant, 'Boissons');
        $alimentation = $this->make($tenant, 'Alimentation');
        $this->make($tenant, 'Sodas', $boissons->id);
        $this->make($tenant, 'Conserves', $alimentation->id);

        $this->asMember($owner, $tenant);

        $response = $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', true);

        $this->assertSame(['Alimentation', 'Conserves', 'Boissons', 'Sodas'], $response->json('data.*.name'));
        $this->assertSame([0, 1, 0, 1], $response->json('data.*.depth'));
        $this->assertSame([1, 0, 1, 0], $response->json('data.*.children_count'));
        $this->assertSame([true, false, true, false], $response->json('data.*.can.add_child'));
    }

    public function test_search_keeps_the_parent_of_a_matching_subcategory(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $boissons = $this->make($tenant, 'Boissons');
        $this->make($tenant, 'Sodas', $boissons->id);
        $this->make($tenant, 'Alimentation');

        $this->asMember($owner, $tenant);

        $this->assertSame(['Boissons', 'Sodas'], $this->getJson(self::URL.'?search=SODA')->json('data.*.name'));
    }

    public function test_create_a_root_and_a_subcategory(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rootId = $this->postJson(self::URL, ['name' => '  Boissons ', 'description' => '  Tout ce qui se boit '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Boissons')
            ->assertJsonPath('data.description', 'Tout ce qui se boit')
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->postJson(self::URL, ['name' => 'Sodas', 'parent_id' => $rootId])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $rootId)
            ->assertJsonPath('data.depth', 1);
    }

    public function test_validation_messages_are_explicit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Indiquez le nom de la catégorie.');

        $this->postJson(self::URL, ['name' => 'Boissons', 'parent_id' => 'inconnu'])
            ->assertStatus(422)
            ->assertJsonPath('errors.parent_id.0', "La catégorie parente n'est pas valide.");
    }

    // ------------------------------------------------------------------ Unicité

    public function test_names_are_unique_among_siblings_only(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');
        $boissons = $this->make($tenantA, 'Boissons');
        $alimentation = $this->make($tenantA, 'Alimentation');
        $this->make($tenantA, 'Sodas', $boissons->id);

        $this->asMember($ownerA, $tenantA);

        $this->postJson(self::URL, ['name' => ' boissons '])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Une catégorie nommée « boissons » existe déjà à cet endroit. Choisissez un autre nom.');

        $this->postJson(self::URL, ['name' => 'SODAS', 'parent_id' => $boissons->id])->assertStatus(422);

        // Même nom, mais ailleurs : autorisé.
        $this->postJson(self::URL, ['name' => 'Sodas', 'parent_id' => $alimentation->id])->assertCreated();
        $this->postJson(self::URL, ['name' => 'Boissons', 'parent_id' => $alimentation->id])->assertCreated();

        $this->asMember($ownerB, $tenantB);
        $this->postJson(self::URL, ['name' => 'Boissons'])->assertCreated();
    }

    // -------------------------------------------------------------- Profondeur

    public function test_the_depth_is_limited_to_two_levels(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $root = $this->make($tenant, 'Boissons');
        $other = $this->make($tenant, 'Alimentation');
        $child = $this->make($tenant, 'Sodas', $root->id);

        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, ['name' => 'Light', 'parent_id' => $child->id])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.parent_id.0',
                'Choisissez une catégorie principale : une sous-catégorie ne peut pas avoir de sous-catégories.',
            );

        $this->putJson(self::URL."/{$root->id}", ['name' => 'Boissons', 'parent_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.parent_id.0',
                'Cette catégorie contient des sous-catégories : elle ne peut pas devenir une sous-catégorie.',
            );

        $this->putJson(self::URL."/{$other->id}", ['name' => 'Alimentation', 'parent_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.parent_id.0', 'Une catégorie ne peut pas être sa propre parente.');
    }

    public function test_a_category_can_be_moved_and_renamed(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $root = $this->make($tenant, 'Boissons');
        $other = $this->make($tenant, 'Alimentation');
        $child = $this->make($tenant, 'Sodas', $root->id);

        $this->asMember($owner, $tenant);

        $this->putJson(self::URL."/{$child->id}", ['name' => 'Sodas', 'parent_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.parent_id', $other->id);

        $this->putJson(self::URL."/{$child->id}", ['name' => 'Jus', 'parent_id' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Jus')
            ->assertJsonPath('data.depth', 0);
    }

    // ------------------------------------------------------- Activation, suppression

    public function test_deactivating_a_parent_deactivates_its_children_and_reactivation_is_explicit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $root = $this->make($tenant, 'Boissons');
        $child = $this->make($tenant, 'Sodas', $root->id);

        $this->asMember($owner, $tenant);

        $this->postJson(self::URL."/{$root->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($child->fresh()->is_active);

        $this->postJson(self::URL."/{$child->id}/activate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'parent_inactive')
            ->assertJsonPath('message', 'Réactivez d\'abord la catégorie parente « Boissons ».');

        $this->postJson(self::URL."/{$root->id}/activate")->assertOk();
        $this->assertFalse($child->fresh()->is_active);

        $this->postJson(self::URL."/{$child->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_a_subcategory_cannot_be_created_under_an_inactive_parent(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $root = $this->make($tenant, 'Boissons');

        $this->asMember($owner, $tenant);
        $this->postJson(self::URL."/{$root->id}/deactivate")->assertOk();

        $this->postJson(self::URL, ['name' => 'Sodas', 'parent_id' => $root->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.parent_id.0', 'Cette catégorie parente est désactivée. Choisissez-en une autre.');
    }

    public function test_delete_is_refused_while_subcategories_exist(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $root = $this->make($tenant, 'Boissons');
        $child = $this->make($tenant, 'Sodas', $root->id);

        $this->asMember($owner, $tenant);

        $this->deleteJson(self::URL."/{$root->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'category_has_children');

        $this->deleteJson(self::URL."/{$child->id}")->assertNoContent();
        $this->deleteJson(self::URL."/{$root->id}")->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $root->id]);
    }

    // ------------------------------------------------------------- Lecture seule

    public function test_an_expired_company_can_read_but_not_write(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->make($tenant, 'Boissons');
        $tenant->forceFill(['status' => TenantStatus::Expired])->save();

        $this->asMember($owner, $tenant);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.can.create', false)
            ->assertJsonPath('data.0.can.update', false);

        $this->postJson(self::URL, ['name' => 'Interdite'])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_read_only');
    }

    // ------------------------------------------------------ Garde-fous de la base

    public function test_the_database_refuses_a_third_level(): void
    {
        [$tenant] = $this->createTenant();
        $root = $this->make($tenant, 'Boissons');
        $child = $this->make($tenant, 'Sodas', $root->id);

        $this->expectException(QueryException::class);

        $this->make($tenant, 'Light', $child->id);
    }

    public function test_the_database_refuses_a_parent_from_another_company(): void
    {
        [$tenantA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $foreignRoot = $this->make($tenantB, 'Boissons');

        $this->expectException(QueryException::class);

        $this->make($tenantA, 'Sodas', $foreignRoot->id);
    }
}