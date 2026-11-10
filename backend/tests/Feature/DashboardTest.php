<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithStock, InteractsWithTenants;

    private const URL = '/api/v1/dashboard';

    public function test_an_empty_company_has_a_quiet_dashboard(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.articles_active', 0)
            ->assertJsonPath('data.warehouses_active', 1)
            ->assertJsonPath('data.stock_value', '0')
            ->assertJsonPath('data.alerts.total', 0)
            ->assertJsonPath('data.entries.count_30d', 0)
            ->assertJsonPath('data.entries.total_30d', '0')
            ->assertJsonPath('data.entries.recent', []);
    }

    public function test_the_dashboard_summarizes_stock_value_and_recent_entries(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $oil = $this->article($tenant, 'Huile', 'bidon');
        $this->article($tenant, 'Ancien produit', 'sac', ['is_active' => false]);

        $this->receive($tenant, $rice, '10', '500');
        $this->receive($tenant, $rice, '3', '550');
        $this->receive($tenant, $oil, '4', '15000');
        $this->receive($tenant, $oil, '2', '15000', null, $this->today($tenant, -60));

        $response = $this->getJson(self::URL)->assertOk();

        $response
            ->assertJsonPath('data.articles_active', 2)
            ->assertJsonPath('data.stock_value', '96650')
            ->assertJsonPath('data.entries.count_30d', 3)
            ->assertJsonPath('data.entries.total_30d', '66650');

        $recent = $response->json('data.entries.recent');

        $this->assertCount(4, $recent);
        $this->assertSame(['ENT-000004', 'ENT-000003', 'ENT-000002', 'ENT-000001'], array_column($recent, 'number'));
        $this->assertSame('validated', $recent[0]['status']);
    }

    public function test_the_stock_value_follows_the_remaining_quantity_of_each_lot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '10', '500');

        \Illuminate\Support\Facades\DB::table('stock_lots')->update(['quantity_remaining' => '4']);

        $this->getJson(self::URL)->assertJsonPath('data.stock_value', '2000');
    }

    public function test_costs_and_entries_are_hidden_from_roles_without_access(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $this->receive($tenant, $this->article($tenant, 'Riz', 'sac', ['min_stock' => '50']), '10');

        foreach ([TenantRole::Seller, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)
                ->assertOk()
                ->assertJsonPath('data.stock_value', null)
                ->assertJsonPath('data.entries', null)
                ->assertJsonPath('data.articles_active', 1)
                ->assertJsonPath('data.alerts.low_stock', 1);
        }
    }

    public function test_each_company_only_sees_its_own_figures(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerB, $tenantB);
        $this->receive($tenantB, $this->article($tenantB, 'Riz', 'sac'), '10');

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)
            ->assertJsonPath('data.articles_active', 0)
            ->assertJsonPath('data.stock_value', '0')
            ->assertJsonPath('data.entries.count_30d', 0);
    }
}