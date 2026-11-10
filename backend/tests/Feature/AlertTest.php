<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use InteractsWithStock, InteractsWithTenants;

    private const SUMMARY = '/api/v1/alerts/summary';

    private const LIST = '/api/v1/alerts';

    // ------------------------------------------------------------- Expirations

    public function test_expired_and_expiring_lots_use_the_alert_delay_of_each_article(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true, 'expiry_alert_days' => 30]);
        $yogurt = $this->article($tenant, 'Yaourt', 'sac', ['track_expiry' => true, 'expiry_alert_days' => 7]);
        $entered = $this->today($tenant, -90);

        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, -5), $entered);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 10), $entered);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 90), $entered);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 0), $entered);
        $this->receive($tenant, $yogurt, '10', '500', $this->today($tenant, 10), $entered);
        $this->receive($tenant, $yogurt, '10', '500', $this->today($tenant, 7), $entered);

        $this->getJson(self::SUMMARY)
            ->assertOk()
            ->assertJsonPath('data.expired', 1)
            ->assertJsonPath('data.expiring', 3)
            ->assertJsonPath('data.low_stock', 0)
            ->assertJsonPath('data.out_of_stock', 0)
            ->assertJsonPath('data.total', 4);
    }

    public function test_a_lot_without_remaining_stock_raises_no_expiry_alert(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, -5), $this->today($tenant, -60));

        $this->getJson(self::SUMMARY)->assertJsonPath('data.expired', 1);

        DB::table('stock_lots')->update(['quantity_remaining' => '0']);

        $this->getJson(self::SUMMARY)->assertJsonPath('data.expired', 0);
    }

    public function test_the_expiry_lists_are_detailed_and_sorted_by_date(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $juice = $this->article($tenant, 'Jus', 'sac', ['track_expiry' => true]);
        $entered = $this->today($tenant, -90);

        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 12), $entered);
        $this->receive($tenant, $juice, '4', '700', $this->today($tenant, 3), $entered);
        $this->receive($tenant, $milk, '20', '500', $this->today($tenant, -4), $entered);

        $expired = $this->getJson(self::LIST.'?type=expired')->assertOk();

        $this->assertSame(['Lait'], $expired->json('data.*.article.name'));
        $expired
            ->assertJsonPath('data.0.lot.code', 'LOT-000003')
            ->assertJsonPath('data.0.lot.days_to_expiry', -4)
            ->assertJsonPath('data.0.lot.quantity', '20')
            ->assertJsonPath('data.0.lot.value', '10000')
            ->assertJsonPath('data.0.lot.warehouse', 'Entrepôt principal')
            ->assertJsonPath('data.0.stock', null)
            ->assertJsonPath('meta.total', 1);

        $expiring = $this->getJson(self::LIST.'?type=expiring')->assertOk();

        $this->assertSame(['Jus', 'Lait'], $expiring->json('data.*.article.name'));
        $this->assertSame([3, 12], $expiring->json('data.*.lot.days_to_expiry'));
    }

    // ----------------------------------------------------------------- Stock

    public function test_low_stock_and_out_of_stock_rules(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $a = $this->article($tenant, 'A bas', 'pce', ['min_stock' => '10']);
        $b = $this->article($tenant, 'B limite', 'pce', ['min_stock' => '10']);
        $c = $this->article($tenant, 'C suffisant', 'pce', ['min_stock' => '10']);
        $d = $this->article($tenant, 'D vidé', 'pce', ['min_stock' => '10']);
        $this->article($tenant, 'E neuf sans seuil', 'pce');
        $this->article($tenant, 'F neuf avec seuil', 'pce', ['min_stock' => '5']);
        $g = $this->article($tenant, 'G inactif', 'pce', ['min_stock' => '10']);

        $this->receive($tenant, $a, '8');
        $this->receive($tenant, $b, '10');
        $this->receive($tenant, $c, '11');
        $this->receive($tenant, $d, '5');
        $this->receive($tenant, $g, '1');

        DB::table('stock_lots')->where('article_id', $d->id)->update(['quantity_remaining' => '0']);
        $g->forceFill(['is_active' => false])->save();

        $low = $this->getJson(self::LIST.'?type=low_stock')->assertOk();

        $this->assertSame(['A bas', 'B limite'], $low->json('data.*.article.name'));
        $low->assertJsonPath('data.0.stock.quantity', '8')->assertJsonPath('data.0.stock.minimum', '10');

        $out = $this->getJson(self::LIST.'?type=out_of_stock')->assertOk();

        $this->assertSame(['D vidé', 'F neuf avec seuil'], $out->json('data.*.article.name'));
        $this->assertTrue($out->json('data.0.stock.ever_stocked'));
        $this->assertFalse($out->json('data.1.stock.ever_stocked'));

        $this->getJson(self::SUMMARY)
            ->assertJsonPath('data.low_stock', 2)
            ->assertJsonPath('data.out_of_stock', 2);
    }

    public function test_decimal_quantities_follow_the_unit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'kg', ['min_stock' => '10']);
        $this->receive($tenant, $rice, '2.5');

        $this->getJson(self::LIST.'?type=low_stock')
            ->assertJsonPath('data.0.stock.quantity', '2.5')
            ->assertJsonPath('data.0.stock.minimum', '10');
    }

    // ----------------------------------------------------- Droits, isolation

    public function test_every_role_sees_alerts_but_costs_are_hidden_from_non_managers(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, -2), $this->today($tenant, -30));

        $this->getJson(self::LIST.'?type=expired')->assertJsonPath('data.0.lot.value', '5000');

        foreach ([TenantRole::Seller, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::SUMMARY)->assertOk()->assertJsonPath('data.expired', 1);

            $response = $this->getJson(self::LIST.'?type=expired')->assertOk();

            $this->assertArrayNotHasKey('value', $response->json('data.0.lot'));
        }
    }

    public function test_each_company_only_sees_its_own_alerts(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerB, $tenantB);
        $this->article($tenantB, 'Autre', 'pce', ['min_stock' => '5']);
        $this->getJson(self::SUMMARY)->assertJsonPath('data.out_of_stock', 1);

        $this->asMember($ownerA, $tenantA);
        $this->getJson(self::SUMMARY)
            ->assertOk()
            ->assertJsonPath('data.out_of_stock', 0)
            ->assertJsonPath('data.total', 0);
    }

    // --------------------------------------------------- Validation, pages

    public function test_the_type_is_required_and_validated(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->getJson(self::LIST)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type' => "Ce type d'alerte n'est pas valide."]);

        $this->getJson(self::LIST.'?type=inconnu')->assertStatus(422);
    }

    public function test_alert_lists_are_paginated(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        for ($i = 1; $i <= 25; $i++) {
            $this->article($tenant, sprintf('Produit %02d', $i), 'pce', ['min_stock' => '5']);
        }

        $page = $this->getJson(self::LIST.'?type=out_of_stock&per_page=10&page=3')->assertOk();

        $this->assertCount(5, $page->json('data'));
        $page->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.current_page', 3);

        $this->assertSame('Produit 21', $page->json('data.0.article.name'));
    }

    public function test_alerts_require_a_session(): void
    {
        $this->getJson(self::SUMMARY)->assertStatus(401);
    }
}