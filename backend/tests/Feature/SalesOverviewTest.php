<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use Tests\Concerns\InteractsWithSales;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SalesOverviewTest extends TestCase
{
    use InteractsWithSales, InteractsWithStock, InteractsWithTenants;

    private const DASHBOARD = '/api/v1/dashboard';

    public function test_a_company_without_sales_has_quiet_figures(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->getJson(self::DASHBOARD)
            ->assertOk()
            ->assertJsonPath('data.sales.today.count', 0)
            ->assertJsonPath('data.sales.today.total', '0')
            ->assertJsonPath('data.sales.month.count', 0)
            ->assertJsonPath('data.sales.month.total', '0')
            ->assertJsonPath('data.sales.month.margin', '0')
            ->assertJsonPath('data.sales.receivables.total_due', '0')
            ->assertJsonPath('data.sales.receivables.customers_count', 0)
            ->assertJsonPath('data.sales.receivables.sales_count', 0)
            ->assertJsonPath('data.sales.receivables.oldest_date', null);
    }

    public function test_the_dashboard_summarizes_sales_margin_and_receivables(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '50', '500', null, $this->today($tenant, -60));

        $kofi = $this->makeCustomer($tenant, 'Kofi');
        $awa = $this->makeCustomer($tenant, 'Awa');

        $this->sell($tenant, [$this->saleLine($rice, '3')], [$this->pay('cash', '2100')])->assertCreated();
        $this->sell($tenant, [$this->saleLine($rice, '2')], [], ['customer_id' => $kofi->id])->assertCreated();
        $this->sell($tenant, [$this->saleLine($rice, '1')], [$this->pay('cash', '300')], ['customer_id' => $awa->id])->assertCreated();
        $this->sell($tenant, [$this->saleLine($rice, '1')], [], [
            'customer_id' => $kofi->id,
            'sale_date' => $this->today($tenant, -45),
        ])->assertCreated();

        $cancelled = $this->sell($tenant, [$this->saleLine($rice, '1')], [$this->pay('cash', '700')])
            ->assertCreated()
            ->json('data.id');
        $this->postJson("/api/v1/sales/{$cancelled}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->getJson(self::DASHBOARD)
            ->assertOk()
            ->assertJsonPath('data.sales.today.count', 3)
            ->assertJsonPath('data.sales.today.total', '4200')
            ->assertJsonPath('data.sales.month.count', 3)
            ->assertJsonPath('data.sales.month.total', '4200')
            ->assertJsonPath('data.sales.month.margin', '1200')
            ->assertJsonPath('data.sales.receivables.total_due', '2500')
            ->assertJsonPath('data.sales.receivables.customers_count', 2)
            ->assertJsonPath('data.sales.receivables.sales_count', 3)
            ->assertJsonPath('data.sales.receivables.oldest_date', $this->today($tenant, -45));
    }

    public function test_margin_is_hidden_from_sellers_and_sales_from_other_roles(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10', '500');
        $this->sell($tenant, [$this->saleLine($rice, '3')], [$this->pay('cash', '2100')])->assertCreated();

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);

        $this->getJson(self::DASHBOARD)
            ->assertOk()
            ->assertJsonPath('data.sales.today.total', '2100')
            ->assertJsonPath('data.sales.month.margin', null);

        foreach ([TenantRole::StockManager, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::DASHBOARD)->assertOk()->assertJsonPath('data.sales', null);
        }
    }

    public function test_each_company_only_sees_its_own_sales_figures(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerB, $tenantB);
        $rice = $this->article($tenantB, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenantB, $rice, '10');
        $this->sell($tenantB, [$this->saleLine($rice, '3')], [$this->pay('cash', '2100')])->assertCreated();

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::DASHBOARD)
            ->assertJsonPath('data.sales.today.count', 0)
            ->assertJsonPath('data.sales.receivables.total_due', '0');
    }

    public function test_customers_can_be_filtered_by_what_they_owe(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '50');

        $kofi = $this->makeCustomer($tenant, 'Kofi');
        $awa = $this->makeCustomer($tenant, 'Awa');
        $zola = $this->makeCustomer($tenant, 'Zola');
        $this->makeCustomer($tenant, 'Moussa');

        $this->sell($tenant, [$this->saleLine($rice, '2')], [], ['customer_id' => $kofi->id])->assertCreated();
        $this->sell($tenant, [$this->saleLine($rice, '1')], [$this->pay('cash', '700')], ['customer_id' => $awa->id])->assertCreated();

        $cancelled = $this->sell($tenant, [$this->saleLine($rice, '1')], [], ['customer_id' => $zola->id])
            ->assertCreated()
            ->json('data.id');
        $this->postJson("/api/v1/sales/{$cancelled}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.total', 4);

        $owing = $this->getJson('/api/v1/customers?has_balance=1')->assertOk();

        $this->assertSame(['Kofi'], $owing->json('data.*.name'));
        $owing->assertJsonPath('data.0.balance_due', '1400');

        $this->getJson('/api/v1/customers?has_balance=1&search=zola')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/customers?has_balance=1&status=inactive')->assertJsonPath('meta.total', 0);
    }
}