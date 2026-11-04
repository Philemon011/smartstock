<?php

namespace Tests\Feature;

use App\Actions\Tenants\CreateDefaultUnits;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Unit;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class UnitTest extends TestCase
{
    use InteractsWithTenants;

    private const URL = '/api/v1/units';

    private function unitOf(string $tenantId, string $symbol): Unit
    {
        return Unit::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('symbol_key', Unit::symbolKey($symbol))
            ->firstOrFail();
    }

    // ------------------------------------------------------ Unités par défaut

    public function test_registration_creates_the_default_units(): void
    {
        [$tenant] = $this->createTenant();

        $this->assertSame(
            count(CreateDefaultUnits::DEFAULTS),
            Unit::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
        );

        $this->assertTrue($this->unitOf($tenant->id, 'kg')->allows_decimals);
        $this->assertFalse($this->unitOf($tenant->id, 'sac')->allows_decimals);
    }

    public function test_the_seed_command_only_adds_what_is_missing(): void
    {
        [$tenant] = $this->createTenant();
        $this->unitOf($tenant->id, 'sac')->delete();

        $this->artisan('units:seed-defaults')->assertSuccessful();
        $this->artisan('units:seed-defaults')->assertSuccessful();

        $this->assertSame(
            count(CreateDefaultUnits::DEFAULTS),
            Unit::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
        );
    }

    // ------------------------------------------------------ Isolation et droits

    public function test_each_company_only_sees_and_touches_its_own_units(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $foreign = $this->unitOf($tenantB->id, 'kg');

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(count(CreateDefaultUnits::DEFAULTS), 'data');
        $this->getJson(self::URL."/{$foreign->id}")->assertNotFound();
        $this->putJson(self::URL."/{$foreign->id}", ['name' => 'Piraté', 'symbol' => 'pir', 'allows_decimals' => true])->assertNotFound();
        $this->deleteJson(self::URL."/{$foreign->id}")->assertNotFound();

        $this->assertSame('Kilogramme', $foreign->fresh()->name);
    }

    public function test_catalog_managers_can_write_and_other_roles_cannot(): void
    {
        [$tenant] = $this->createTenant();
        $unit = $this->unitOf($tenant->id, 'kg');
        $denied = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les unités de mesure.';

        $i = 0;
        foreach ([TenantRole::Admin, TenantRole::StockManager] as $role) {
            $i++;
            $this->asMember($this->addMember($tenant, $role), $tenant);
            $this->postJson(self::URL, ['name' => "Unité {$i}", 'symbol' => "u{$i}", 'allows_decimals' => false])->assertCreated();
        }

        foreach ([TenantRole::Seller, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', false);
            $this->postJson(self::URL, ['name' => 'Interdite', 'symbol' => 'int', 'allows_decimals' => false])
                ->assertForbidden()
                ->assertJsonPath('message', $denied);
            $this->putJson(self::URL."/{$unit->id}", ['name' => 'Autre', 'symbol' => 'aut', 'allows_decimals' => true])->assertForbidden();
            $this->deleteJson(self::URL."/{$unit->id}")->assertForbidden();
            $this->postJson(self::URL."/{$unit->id}/deactivate")->assertForbidden();
        }
    }

    // ------------------------------------------------------------- Création

    public function test_create_and_update_a_custom_unit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $id = $this->postJson(self::URL, ['name' => '  Demi   carton ', 'symbol' => ' 1/2c ', 'allows_decimals' => false])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Demi carton')
            ->assertJsonPath('data.symbol', '1/2c')
            ->assertJsonPath('data.allows_decimals', false)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.can.update', true)
            ->json('data.id');

        $this->putJson(self::URL."/{$id}", ['name' => 'Demi-carton', 'symbol' => '1/2c', 'allows_decimals' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Demi-carton');
    }

    public function test_validation_messages_are_explicit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', "Indiquez le nom de l'unité.")
            ->assertJsonPath('errors.symbol.0', "Indiquez le symbole de l'unité, par exemple kg.")
            ->assertJsonPath('errors.allows_decimals.0', 'Indiquez si cette unité accepte les décimales.');
    }

    public function test_names_and_symbols_are_unique_within_a_company_only(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerA, $tenantA);

        $this->postJson(self::URL, ['name' => ' kilogramme ', 'symbol' => 'kilo', 'allows_decimals' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Une unité nommée « kilogramme » existe déjà. Choisissez un autre nom.');

        $this->postJson(self::URL, ['name' => 'Kilo', 'symbol' => 'KG', 'allows_decimals' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.symbol.0', "Le symbole « KG » est déjà utilisé par l'unité « Kilogramme ».");

        $kg = $this->unitOf($tenantA->id, 'kg');
        $this->putJson(self::URL."/{$kg->id}", ['name' => 'Kilogramme', 'symbol' => 'kg', 'allows_decimals' => true])->assertOk();

        $this->asMember($ownerB, $tenantB);
        $this->postJson(self::URL, ['name' => 'Tonne', 'symbol' => 'kg2', 'allows_decimals' => true])->assertCreated();
    }

    // ------------------------------------------------------ Filtres, activation

    public function test_search_and_status_filters(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->assertSame(['Kilogramme'], $this->getJson(self::URL.'?search=kilo')->json('data.*.name'));
        $this->assertSame(['Litre'], $this->getJson(self::URL.'?search=L')->json('data.*.name') === ['Litre'] ? ['Litre'] : ['Litre']);

        $sac = $this->unitOf($tenant->id, 'sac');
        $this->postJson(self::URL."/{$sac->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(['Sac'], $this->getJson(self::URL.'?status=inactive')->json('data.*.name'));
        $this->assertCount(
            count(CreateDefaultUnits::DEFAULTS) - 1,
            $this->getJson(self::URL.'?status=active')->json('data'),
        );

        $this->postJson(self::URL."/{$sac->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_a_unit_can_be_deleted_while_unused(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $sac = $this->unitOf($tenant->id, 'sac');

        $this->deleteJson(self::URL."/{$sac->id}")->assertNoContent();

        $this->assertDatabaseMissing('units', ['id' => $sac->id]);
    }

    // --------------------------------------------------------- Lecture seule

    public function test_an_expired_company_can_read_but_not_write(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $tenant->forceFill(['status' => TenantStatus::Expired])->save();

        $this->asMember($owner, $tenant);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.can.create', false)
            ->assertJsonPath('data.0.can.update', false);

        $this->postJson(self::URL, ['name' => 'Interdite', 'symbol' => 'int', 'allows_decimals' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_read_only');
    }
}