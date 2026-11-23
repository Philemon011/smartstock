<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockAvailabilityTest extends TestCase
{
    use InteractsWithStock, InteractsWithTenants;

    private const URL = '/api/v1/stock/articles';

    private function warehouse(Tenant $tenant, string $name): Warehouse
    {
        $warehouse = (new Warehouse)->forceFill(['tenant_id' => $tenant->id, 'name' => $name]);
        $warehouse->save();

        return $warehouse;
    }

    /**
     * @return list<string>
     */
    private function names(string $query): array
    {
        return $this->getJson(self::URL.$query)->assertOk()->json('data.*.name');
    }

    public function test_only_articles_with_stock_in_the_chosen_warehouse_are_listed(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $main = $this->mainWarehouse($tenant);
        $north = $this->warehouse($tenant, 'Dépôt Nord');
        $empty = $this->warehouse($tenant, 'Dépôt vide');

        $rice = $this->article($tenant, 'Riz', 'sac');
        $oil = $this->article($tenant, 'Huile', 'sac');
        $this->article($tenant, 'Jamais reçu', 'sac');

        $this->receive($tenant, $rice, '10');
        $this->receive($tenant, $oil, '5', '500', null, null, $north->id);

        $this->assertSame(['Riz'], $this->names("?warehouse_id={$main->id}"));
        $this->assertSame(['Huile'], $this->names("?warehouse_id={$north->id}"));
        $this->assertSame([], $this->names("?warehouse_id={$empty->id}"));

        $this->getJson(self::URL."?warehouse_id={$main->id}")
            ->assertJsonPath('data.0.id', $rice->id)
            ->assertJsonPath('data.0.available', '10')
            ->assertJsonPath('data.0.unit.symbol', 'sac')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_expired_lots_are_only_listed_in_expired_mode(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $main = $this->mainWarehouse($tenant)->id;
        $entered = $this->today($tenant, -60);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $cheese = $this->article($tenant, 'Fromage', 'sac', ['track_expiry' => true]);

        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, -5), $entered);
        $this->receive($tenant, $milk, '20', '500', $this->today($tenant, 30), $entered);
        $this->receive($tenant, $cheese, '4', '900', $this->today($tenant, -2), $entered);

        $this->assertSame(['Lait'], $this->names("?warehouse_id={$main}"));
        $this->getJson(self::URL."?warehouse_id={$main}")->assertJsonPath('data.0.available', '20');

        $expired = $this->getJson(self::URL."?warehouse_id={$main}&mode=expired")->assertOk();

        $this->assertSame(['Fromage', 'Lait'], $expired->json('data.*.name'));
        $this->assertSame(['4', '10'], $expired->json('data.*.available'));
    }

    public function test_inactive_articles_and_empty_lots_are_not_listed(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $main = $this->mainWarehouse($tenant)->id;

        $active = $this->article($tenant, 'A actif', 'sac');
        $inactive = $this->article($tenant, 'B inactif', 'sac');
        $empty = $this->article($tenant, 'C vidé', 'sac');

        foreach ([$active, $inactive, $empty] as $article) {
            $this->receive($tenant, $article, '5');
        }

        $inactive->forceFill(['is_active' => false])->save();
        DB::table('stock_lots')->where('article_id', $empty->id)->update(['quantity_remaining' => '0']);

        $this->assertSame(['A actif'], $this->names("?warehouse_id={$main}"));
    }

    public function test_decimal_quantities_follow_the_unit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'kg');
        $this->receive($tenant, $rice, '2.5');

        $this->getJson(self::URL.'?warehouse_id='.$this->mainWarehouse($tenant)->id)
            ->assertJsonPath('data.0.available', '2.5');
    }

    public function test_search_and_pagination(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $main = $this->mainWarehouse($tenant)->id;

        foreach (['Riz parfumé', 'Riz local', 'Huile'] as $name) {
            $this->receive($tenant, $this->article($tenant, $name, 'sac'), '3');
        }

        $this->assertSame(['Riz local', 'Riz parfumé'], $this->names("?warehouse_id={$main}&search=riz"));
        $this->assertSame(['Huile'], $this->names("?warehouse_id={$main}&search=ART-000003"));
        $this->assertSame([], $this->names("?warehouse_id={$main}&search=introuvable"));

        for ($i = 1; $i <= 12; $i++) {
            $this->receive($tenant, $this->article($tenant, sprintf('Produit %02d', $i), 'pce'), '1');
        }

        $page = $this->getJson(self::URL."?warehouse_id={$main}&search=produit&per_page=5&page=3")->assertOk();

        $this->assertSame(['Produit 11', 'Produit 12'], $page->json('data.*.name'));
        $page->assertJsonPath('meta.total', 12)->assertJsonPath('meta.last_page', 3);
    }

    public function test_the_warehouse_is_required_and_must_belong_to_the_company(): void
    {
        $this->getJson(self::URL.'?warehouse_id=x')->assertStatus(401);

        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id' => 'Choisissez un entrepôt.']);

        $this->getJson(self::URL.'?warehouse_id=01JZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertJsonValidationErrors(['warehouse_id' => 'Cet entrepôt est introuvable.']);

        $this->getJson(self::URL.'?warehouse_id='.$this->mainWarehouse($tenantB)->id)
            ->assertJsonValidationErrors(['warehouse_id' => 'Cet entrepôt est introuvable.']);

        $this->getJson(self::URL.'?warehouse_id='.$this->mainWarehouse($tenantA)->id.'&mode=inconnu')
            ->assertJsonValidationErrors(['mode' => 'Le type de stock est invalide.']);
    }

    public function test_every_role_can_read_it(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->receive($tenant, $this->article($tenant, 'Riz', 'sac'), '10');
        $main = $this->mainWarehouse($tenant)->id;

        foreach ([TenantRole::Seller, TenantRole::StockManager, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->assertSame(['Riz'], $this->names("?warehouse_id={$main}"));
        }
    }
}