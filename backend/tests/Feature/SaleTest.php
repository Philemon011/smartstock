<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\ArticlePackaging;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use InteractsWithStock, InteractsWithTenants;

    private const URL = '/api/v1/sales';

    // --------------------------------------------------------------- Outils

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Tenant $tenant, array $lines, array $payments = [], array $overrides = []): array
    {
        return array_merge([
            'sale_date' => $this->today($tenant),
            'warehouse_id' => $this->mainWarehouse($tenant)->id,
            'lines' => $lines,
            'payments' => $payments,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(\App\Models\Article $article, string $quantity, array $overrides = []): array
    {
        return array_merge(['article_id' => $article->id, 'entered_quantity' => $quantity], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function pay(string $method, string $amount, ?string $reference = null): array
    {
        return ['method' => $method, 'amount' => $amount, 'reference' => $reference];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, mixed>  $overrides
     */
    private function sell(Tenant $tenant, array $lines, array $payments = [], array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::URL, $this->payload($tenant, $lines, $payments, $overrides));
    }

    private function customer(Tenant $tenant, string $name, array $attributes = []): Customer
    {
        $customer = (new Customer)->forceFill(array_merge(['tenant_id' => $tenant->id, 'name' => $name], $attributes));
        $customer->save();

        return $customer;
    }

    private function secondWarehouse(Tenant $tenant): Warehouse
    {
        $warehouse = (new Warehouse)->forceFill(['tenant_id' => $tenant->id, 'name' => 'Dépôt Nord']);
        $warehouse->save();

        return $warehouse;
    }

    private function remaining(Tenant $tenant, string $code): string
    {
        return rtrim(rtrim((string) DB::table('stock_lots')->where('tenant_id', $tenant->id)->where('code', $code)->value('quantity_remaining'), '0'), '.') ?: '0';
    }

    // ----------------------------------------------------- Droits, isolation

    public function test_sellers_sell_but_stock_managers_and_viewers_cannot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '50');

        $this->sell($tenant, [$this->line($rice, '1')], [$this->pay('cash', '700')])->assertCreated();

        foreach ([TenantRole::Admin, TenantRole::Seller] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);
            $this->sell($tenant, [$this->line($rice, '1')], [$this->pay('cash', '700')])->assertCreated();
        }

        foreach ([TenantRole::StockManager, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', false);
            $this->sell($tenant, [$this->line($rice, '1')], [$this->pay('cash', '700')])
                ->assertForbidden()
                ->assertJsonPath('message', 'Seuls le propriétaire, les administrateurs et les vendeurs peuvent enregistrer des ventes.');
        }
    }

    public function test_each_company_only_sees_its_own_sales(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerB, $tenantB);
        $riceB = $this->article($tenantB, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenantB, $riceB, '10');
        $idB = $this->sell($tenantB, [$this->line($riceB, '1')], [$this->pay('cash', '700')])->assertCreated()->json('data.id');

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL."/{$idB}")->assertNotFound();
    }

    public function test_sales_require_a_session_and_a_writable_company(): void
    {
        $this->getJson(self::URL)->assertStatus(401);

        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10');

        $tenant->forceFill(['status' => 'expired'])->save();

        $this->getJson(self::URL)->assertOk();
        $this->sell($tenant, [$this->line($rice, '1')], [$this->pay('cash', '700')])->assertForbidden();
    }

    // -------------------------------------------------- Vente et prélèvement

    public function test_a_cash_sale_takes_the_stock_and_records_cost_and_margin(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10', '500');

        $response = $this->sell($tenant, [$this->line($rice, '3')], [$this->pay('cash', '2100')])->assertCreated();

        $response
            ->assertJsonPath('data.number', 'VTE-000001')
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.subtotal', '2100')
            ->assertJsonPath('data.discount_total', '0')
            ->assertJsonPath('data.total', '2100')
            ->assertJsonPath('data.amount_paid', '2100')
            ->assertJsonPath('data.balance_due', '0')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.cost_total', '1500')
            ->assertJsonPath('data.margin', '600')
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.lines.0.quantity', '3')
            ->assertJsonPath('data.lines.0.list_price', '700')
            ->assertJsonPath('data.lines.0.unit_price', '700')
            ->assertJsonPath('data.lines.0.discount', null)
            ->assertJsonPath('data.lines.0.line_total', '2100')
            ->assertJsonPath('data.lines.0.cost_total', '1500')
            ->assertJsonPath('data.lines.0.allocations.0.lot.code', 'LOT-000001')
            ->assertJsonPath('data.lines.0.allocations.0.quantity', '3');

        $this->assertSame('7', $this->remaining($tenant, 'LOT-000001'));
        $this->assertSame(1, DB::table('stock_movements')->where('type', 'sale')->where('quantity', '-3.000')->count());
    }

    public function test_the_lot_that_expires_first_is_sold_first(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '800', 'track_expiry' => true]);
        $this->receive($tenant, $rice, '5', '500', $this->today($tenant, 30));
        $this->receive($tenant, $rice, '10', '550', $this->today($tenant, 90));

        $response = $this->sell($tenant, [$this->line($rice, '8')], [$this->pay('cash', '6400')])->assertCreated();

        $this->assertSame(['LOT-000001', 'LOT-000002'], $response->json('data.lines.0.allocations.*.lot.code'));
        $this->assertSame(['5', '3'], $response->json('data.lines.0.allocations.*.quantity'));

        $response
            ->assertJsonPath('data.total', '6400')
            ->assertJsonPath('data.cost_total', '4150')
            ->assertJsonPath('data.margin', '2250');

        $this->assertSame('0', $this->remaining($tenant, 'LOT-000001'));
        $this->assertSame('7', $this->remaining($tenant, 'LOT-000002'));
    }

    public function test_expired_lots_are_never_sold(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['sale_price' => '600', 'track_expiry' => true]);
        $entered = $this->today($tenant, -60);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, -5), $entered);
        $this->receive($tenant, $milk, '20', '500', $this->today($tenant, 30), $entered);

        $this->sell($tenant, [$this->line($milk, '25')], [$this->pay('cash', '15000')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', "Stock insuffisant pour « Lait » dans l'entrepôt « Entrepôt principal » : 20 sac disponible(s) (hors lots expirés), 25 sac demandé(s).");

        $this->sell($tenant, [$this->line($milk, '15')], [$this->pay('cash', '9000')])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.allocations.0.lot.code', 'LOT-000002');

        $this->assertSame('10', $this->remaining($tenant, 'LOT-000001'));
        $this->assertSame('5', $this->remaining($tenant, 'LOT-000002'));
    }

    public function test_an_insufficient_line_changes_nothing(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $oil = $this->article($tenant, 'Huile', 'sac', ['sale_price' => '900']);
        $this->receive($tenant, $rice, '10');
        $this->receive($tenant, $oil, '5');

        $this->sell($tenant, [$this->line($rice, '4'), $this->line($oil, '9')], [$this->pay('cash', '20000')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame('10', $this->remaining($tenant, 'LOT-000001'));
        $this->assertSame('5', $this->remaining($tenant, 'LOT-000002'));
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'sale')->count());

        $this->sell($tenant, [$this->line($rice, '1')], [$this->pay('cash', '700')])
            ->assertCreated()
            ->assertJsonPath('data.number', 'VTE-000001');
    }

    public function test_lines_of_the_same_article_use_the_stock_one_after_the_other(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '50');

        $this->sell($tenant, [$this->line($rice, '30'), $this->line($rice, '30')], [$this->pay('cash', '42000')])
            ->assertStatus(409)
            ->assertJsonPath('message', "Stock insuffisant pour « Riz » dans l'entrepôt « Entrepôt principal » : 20 sac disponible(s) (hors lots expirés), 30 sac demandé(s).");

        $this->assertSame('50', $this->remaining($tenant, 'LOT-000001'));

        $this->sell($tenant, [$this->line($rice, '30'), $this->line($rice, '20')], [$this->pay('cash', '35000')])
            ->assertCreated()
            ->assertJsonPath('data.total', '35000');

        $this->assertSame('0', $this->remaining($tenant, 'LOT-000001'));
    }

    public function test_only_the_stock_of_the_chosen_warehouse_is_used(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $north = $this->secondWarehouse($tenant);
        $this->receive($tenant, $rice, '10', '500', null, null, $north->id);

        $this->sell($tenant, [$this->line($rice, '5')], [$this->pay('cash', '3500')])
            ->assertStatus(409)
            ->assertJsonPath('message', "Stock insuffisant pour « Riz » dans l'entrepôt « Entrepôt principal » : 0 sac disponible(s) (hors lots expirés), 5 sac demandé(s).");

        $this->sell($tenant, [$this->line($rice, '5')], [$this->pay('cash', '3500')], ['warehouse_id' => $north->id])
            ->assertCreated();

        $this->assertSame('5', $this->remaining($tenant, 'LOT-000001'));
    }

    public function test_a_sale_cannot_precede_the_reception_of_the_lot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10');

        $this->sell($tenant, [$this->line($rice, '1')], [$this->pay('cash', '700')], ['sale_date' => $this->today($tenant, -1)])
            ->assertStatus(409)
            ->assertJsonPath('code', 'sale_before_reception');

        $this->assertSame('10', $this->remaining($tenant, 'LOT-000001'));
    }

    public function test_a_sale_in_a_packaging_converts_quantity_and_price(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'kg', ['sale_price' => '100']);
        $packaging = (new ArticlePackaging)->forceFill([
            'tenant_id' => $tenant->id, 'article_id' => $rice->id, 'name' => 'Sac', 'quantity' => '25',
        ]);
        $packaging->save();
        $this->receive($tenant, $rice, '100', '80');

        $this->sell($tenant, [$this->line($rice, '2', ['packaging_id' => $packaging->id])], [$this->pay('cash', '5000')])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.entered_unit', 'Sac')
            ->assertJsonPath('data.lines.0.entered_quantity', '2')
            ->assertJsonPath('data.lines.0.quantity', '50')
            ->assertJsonPath('data.lines.0.list_price', '2500')
            ->assertJsonPath('data.total', '5000')
            ->assertJsonPath('data.cost_total', '4000')
            ->assertJsonPath('data.margin', '1000');

        $this->assertSame('50', $this->remaining($tenant, 'LOT-000001'));
    }

    // ------------------------------------------------------ Prix et remises

    public function test_the_seller_can_change_the_price_and_the_list_price_is_kept(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10');

        $this->sell($tenant, [$this->line($rice, '3', ['unit_price' => '650'])], [$this->pay('cash', '1950')])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.list_price', '700')
            ->assertJsonPath('data.lines.0.unit_price', '650')
            ->assertJsonPath('data.total', '1950');

        $this->sell($tenant, [$this->line($rice, '1', ['unit_price' => '-5'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.unit_price']);
    }

    public function test_line_discounts_are_computed_and_rounded_in_the_currency(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '20');

        $this->sell($tenant, [$this->line($rice, '3', ['discount_type' => 'percent', 'discount_value' => '10'])], [$this->pay('cash', '1890')])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '2100')
            ->assertJsonPath('data.discount_total', '210')
            ->assertJsonPath('data.total', '1890')
            ->assertJsonPath('data.lines.0.discount.type', 'percent')
            ->assertJsonPath('data.lines.0.discount.value', '10')
            ->assertJsonPath('data.lines.0.discount.amount', '210')
            ->assertJsonPath('data.lines.0.line_total', '1890');

        $this->sell($tenant, [$this->line($rice, '3', ['discount_type' => 'amount', 'discount_value' => '300'])], [$this->pay('cash', '1800')])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.discount.value', '300')
            ->assertJsonPath('data.total', '1800');

        $this->sell($tenant, [$this->line($rice, '3', ['discount_type' => 'percent', 'discount_value' => '12.5'])], [$this->pay('cash', '1837')])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.discount.value', '12.5')
            ->assertJsonPath('data.lines.0.discount.amount', '263')
            ->assertJsonPath('data.total', '1837');
    }

    public function test_discount_rules(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '20');

        $this->sell($tenant, [$this->line($rice, '3', ['discount_type' => 'amount', 'discount_value' => '5000'])])
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.discount_value']);

        $this->sell($tenant, [$this->line($rice, '3', ['discount_type' => 'percent', 'discount_value' => '101'])])
            ->assertJsonValidationErrors(['lines.0.discount_value' => 'Le pourcentage de remise doit être compris entre 0 et 100.']);

        $this->sell($tenant, [$this->line($rice, '3', ['discount_type' => 'percent'])])
            ->assertJsonValidationErrors(['lines.0.discount_value' => 'Indiquez la valeur de la remise.']);

        $this->sell($tenant, [$this->line($rice, '3', ['discount_value' => '10'])])
            ->assertJsonValidationErrors(['lines.0.discount_type' => 'Choisissez le type de remise (pourcentage ou montant).']);

        $this->sell($tenant, [$this->line($rice, '3', ['discount_type' => 'percent', 'discount_value' => '100'])])
            ->assertStatus(409)
            ->assertJsonPath('code', 'sale_total_zero');
    }

    // ------------------------------------------------------------- Paiements

    public function test_cash_gives_change_when_it_exceeds_the_amount_due(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10');

        $response = $this->sell($tenant, [$this->line($rice, '3')], [$this->pay('cash', '5000')])->assertCreated();

        $response
            ->assertJsonPath('data.amount_paid', '2100')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payments.0.method', 'cash')
            ->assertJsonPath('data.payments.0.amount', '2100')
            ->assertJsonPath('data.payments.0.tendered', '5000')
            ->assertJsonPath('data.payments.0.change_given', '2900');
    }

    public function test_other_methods_cannot_exceed_the_amount_due(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10');

        $this->sell($tenant, [$this->line($rice, '3')], [$this->pay('mtn_momo', '3000')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_exceeds_balance');

        $this->assertSame('10', $this->remaining($tenant, 'LOT-000001'));
    }

    public function test_a_sale_can_be_paid_with_several_methods(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '20');

        $first = $this->sell($tenant, [$this->line($rice, '3')], [
            $this->pay('cash', '1000'),
            $this->pay('mtn_momo', '1100', 'MP2611.1234'),
        ])->assertCreated();

        $first->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.amount_paid', '2100');
        $this->assertEqualsCanonicalizing(['cash', 'mtn_momo'], $first->json('data.payments.*.method'));

        $second = $this->sell($tenant, [$this->line($rice, '3')], [
            $this->pay('moov_money', '1000'),
            $this->pay('cash', '5000'),
        ])->assertCreated();

        $cash = collect($second->json('data.payments'))->firstWhere('method', 'cash');

        $this->assertSame('1100', $cash['amount']);
        $this->assertSame('5000', $cash['tendered']);
        $this->assertSame('3900', $cash['change_given']);

        $this->sell($tenant, [$this->line($rice, '3')], [
            $this->pay('card', '2100'),
            $this->pay('cash', '100'),
        ])->assertStatus(409)->assertJsonPath('code', 'sale_already_paid');
    }

    // ---------------------------------------------------------------- Crédit

    public function test_a_credit_sale_requires_a_customer_and_tracks_the_balance(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '20');
        $kofi = $this->customer($tenant, 'Kofi Mensah');

        $this->sell($tenant, [$this->line($rice, '3')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'customer_required')
            ->assertJsonPath('message', 'Choisissez un client : une vente à crédit doit être rattachée à un client.');

        $this->sell($tenant, [$this->line($rice, '3')], [$this->pay('cash', '1000')])
            ->assertStatus(409)->assertJsonPath('code', 'customer_required');

        $this->sell($tenant, [$this->line($rice, '3')], [], ['customer_id' => $kofi->id])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.amount_paid', '0')
            ->assertJsonPath('data.balance_due', '2100')
            ->assertJsonPath('data.customer.name', 'Kofi Mensah')
            ->assertJsonPath('data.can.collect', true);

        $this->sell($tenant, [$this->line($rice, '3')], [$this->pay('mtn_momo', '1000')], ['customer_id' => $kofi->id])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.amount_paid', '1000')
            ->assertJsonPath('data.balance_due', '1100');
    }

    public function test_the_credit_limit_covers_everything_the_customer_already_owes(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '30');

        $none = $this->customer($tenant, 'Sans crédit', ['credit_limit' => '0']);
        $small = $this->customer($tenant, 'Petit plafond', ['credit_limit' => '1000']);
        $some = $this->customer($tenant, 'Plafond moyen', ['credit_limit' => '5000']);
        $free = $this->customer($tenant, 'Sans plafond');

        $this->sell($tenant, [$this->line($rice, '3')], [], ['customer_id' => $none->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'credit_limit_exceeded')
            ->assertJsonPath('message', "« Sans crédit » n'a pas droit au crédit : son plafond est à zéro.");

        $this->sell($tenant, [$this->line($rice, '3')], [], ['customer_id' => $small->id])
            ->assertStatus(409)->assertJsonPath('code', 'credit_limit_exceeded');

        $this->sell($tenant, [$this->line($rice, '3')], [], ['customer_id' => $some->id])->assertCreated();
        $this->sell($tenant, [$this->line($rice, '3')], [], ['customer_id' => $some->id])->assertCreated();
        $this->sell($tenant, [$this->line($rice, '3')], [], ['customer_id' => $some->id])
            ->assertStatus(409)->assertJsonPath('code', 'credit_limit_exceeded');

        $this->sell($tenant, [$this->line($rice, '3')], [$this->pay('cash', '2100')], ['customer_id' => $some->id])->assertCreated();

        for ($i = 0; $i < 3; $i++) {
            $this->sell($tenant, [$this->line($rice, '1')], [], ['customer_id' => $free->id])->assertCreated();
        }
    }

    // ------------------------------------------------------------ Validation

    public function test_header_and_payment_validation_messages(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10');
        $lines = [$this->line($rice, '1')];

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'warehouse_id' => "Choisissez l'entrepôt d'où part la marchandise.",
                'lines' => 'Ajoutez au moins un article à la vente.',
            ]);

        $this->sell($tenant, $lines, [], ['sale_date' => $this->today($tenant, 2)])
            ->assertJsonValidationErrors(['sale_date' => 'La date de la vente ne peut pas être dans le futur.']);

        $this->sell($tenant, $lines, [], ['customer_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])
            ->assertJsonValidationErrors(['customer_id' => 'Ce client est introuvable.']);

        $inactiveCustomer = $this->customer($tenant, 'Ancien', ['is_active' => false]);

        $this->sell($tenant, $lines, [], ['customer_id' => $inactiveCustomer->id])
            ->assertJsonValidationErrors(['customer_id' => 'Ce client est désactivé. Choisissez-en un autre.']);

        $inactiveWarehouse = $this->secondWarehouse($tenant);
        $inactiveWarehouse->forceFill(['is_active' => false])->save();

        $this->sell($tenant, $lines, [], ['warehouse_id' => $inactiveWarehouse->id])
            ->assertJsonValidationErrors(['warehouse_id' => 'Cet entrepôt est désactivé. Choisissez-en un autre.']);

        $this->sell($tenant, $lines, [['method' => 'bitcoin', 'amount' => '700']])
            ->assertJsonValidationErrors(['payments.0.method' => "Ce mode de paiement n'est pas valide."]);

        $this->sell($tenant, $lines, [$this->pay('cash', '100.5')])
            ->assertJsonValidationErrors(['payments.0.amount' => 'Le montant est invalide : saisissez un montant entier, sans décimales.']);

        $this->sell($tenant, $lines, [$this->pay('cash', '0')])
            ->assertJsonValidationErrors(['payments.0.amount' => 'Le montant du paiement doit être supérieur à zéro.']);
    }

    public function test_line_validation_messages(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $old = $this->article($tenant, 'Ancien', 'sac', ['is_active' => false]);
        $other = $this->article($tenant, 'Huile', 'sac');
        $packaging = (new ArticlePackaging)->forceFill([
            'tenant_id' => $tenant->id, 'article_id' => $other->id, 'name' => 'Carton', 'quantity' => '12',
        ]);
        $packaging->save();

        $this->sell($tenant, [$this->line($rice, '2.5')])
            ->assertJsonValidationErrors(['lines.0.entered_quantity' => "Cette quantité représente 2.5 sac, mais l'unité « Sac » n'accepte pas les décimales."]);

        $this->sell($tenant, [$this->line($rice, 'abc')])
            ->assertJsonValidationErrors(['lines.0.entered_quantity' => 'La quantité est invalide : utilisez des chiffres avec au plus 3 décimales.']);

        $this->sell($tenant, [$this->line($rice, '1', ['packaging_id' => $packaging->id])])
            ->assertJsonValidationErrors(['lines.0.packaging_id' => "Ce conditionnement n'appartient pas à cet article."]);

        $this->sell($tenant, [$this->line($old, '1')])
            ->assertJsonValidationErrors(['lines.0.article_id' => 'Cet article est désactivé : choisissez-en un autre.']);
    }

    // ----------------------------------------- Liste, détail, confidentialité

    public function test_the_list_supports_filters_search_and_pagination(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '50');
        $kofi = $this->customer($tenant, 'Kofi Mensah', ['phone' => '+229 01 02 03 04']);
        $awa = $this->customer($tenant, 'Awa Sow');

        $paid = $this->sell($tenant, [$this->line($rice, '1')], [$this->pay('cash', '700')])->assertCreated()->json('data.id');
        $this->sell($tenant, [$this->line($rice, '1')], [], ['customer_id' => $kofi->id])->assertCreated();
        $this->sell($tenant, [$this->line($rice, '2')], [$this->pay('cash', '500')], ['customer_id' => $awa->id])->assertCreated();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.can.create', true);
        $this->getJson(self::URL.'?payment_status=paid')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $paid);
        $this->getJson(self::URL.'?payment_status=unpaid')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?payment_status=partial')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?search=kofi')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?search=01 02')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?search=VTE-000003')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?customer_id='.$awa->id)->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?from='.$this->today($tenant, 1))->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?payment_status=inconnu')->assertStatus(422);

        $page = $this->getJson(self::URL.'?per_page=2&page=2')->assertOk();

        $this->assertCount(1, $page->json('data'));
        $page->assertJsonPath('meta.last_page', 2);
    }

    public function test_costs_and_margins_are_hidden_from_sellers(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10', '500');
        $id = $this->sell($tenant, [$this->line($rice, '3')], [$this->pay('cash', '2100')])->assertCreated()->json('data.id');

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);

        $response = $this->getJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data.total', '2100');

        $response->assertJsonMissingPath('data.cost_total')
            ->assertJsonMissingPath('data.margin')
            ->assertJsonMissingPath('data.lines.0.cost_total')
            ->assertJsonMissingPath('data.lines.0.allocations.0.unit_cost');

        $this->getJson(self::URL)->assertOk()->assertJsonMissingPath('data.0.cost_total');
    }

    public function test_records_used_by_a_sale_are_protected(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $north = $this->secondWarehouse($tenant);
        $kofi = $this->customer($tenant, 'Kofi');
        $this->receive($tenant, $rice, '10', '500', null, null, $north->id);

        $this->sell($tenant, [$this->line($rice, '1')], [$this->pay('cash', '700')], [
            'warehouse_id' => $north->id,
            'customer_id' => $kofi->id,
        ])->assertCreated();

        $this->deleteJson("/api/v1/customers/{$kofi->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'customer_used');
        $this->deleteJson("/api/v1/warehouses/{$north->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/articles/{$rice->id}")->assertStatus(409);
    }

    public function test_sale_meta_lists_the_payment_methods(): void
    {
        $response = $this->getJson('/api/v1/meta/sales')->assertOk();

        $methods = collect($response->json('payment_methods') ?? $response->json('data.payment_methods'));

        $this->assertCount(5, $methods);
        $this->assertTrue($methods->firstWhere('value', 'cash')['is_cash']);
        $this->assertFalse($methods->firstWhere('value', 'mtn_momo')['is_cash']);
        $this->assertSame('Moov Money', $methods->firstWhere('value', 'moov_money')['label']);
    }

    // ------------------------------------------------ Garde-fous PostgreSQL

    private function oneSale(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, '10');
        $this->sell($tenant, [$this->line($rice, '2')], [$this->pay('cash', '1400')])->assertCreated();
    }

    public function test_the_database_protects_a_validated_sale(): void
    {
        $this->oneSale();

        $this->expectException(QueryException::class);

        DB::table('sales')->update(['sale_date' => '2020-01-01']);
    }

    public function test_the_database_protects_the_lines_of_a_sale(): void
    {
        $this->oneSale();

        $this->expectException(QueryException::class);

        DB::table('sale_lines')->update(['quantity' => '1']);
    }

    public function test_the_database_protects_the_allocations_of_a_sale(): void
    {
        $this->oneSale();

        $this->expectException(QueryException::class);

        DB::table('sale_allocations')->delete();
    }

    public function test_the_database_protects_the_payments(): void
    {
        $this->oneSale();

        $this->expectException(QueryException::class);

        DB::table('sale_payments')->update(['amount' => '1']);
    }
}