<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Article;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSales;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SaleSettlementTest extends TestCase
{
    use InteractsWithSales, InteractsWithStock, InteractsWithTenants;

    private const URL = '/api/v1/sales';

    private function stockedRice(Tenant $tenant, string $quantity = '50'): Article
    {
        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $this->receive($tenant, $rice, $quantity, '500');

        return $rice;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    private function creditSale(Tenant $tenant, Article $rice, Customer $customer, string $quantity = '3', array $payments = []): string
    {
        return $this->sell($tenant, [$this->saleLine($rice, $quantity)], $payments, ['customer_id' => $customer->id])
            ->assertCreated()
            ->json('data.id');
    }

    // ------------------------------------------------- Annulation d'une vente

    public function test_cancelling_a_sale_restores_the_exact_lots_and_voids_the_payments(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '800', 'track_expiry' => true]);
        $this->receive($tenant, $rice, '5', '500', $this->today($tenant, 30));
        $this->receive($tenant, $rice, '10', '550', $this->today($tenant, 90));

        $id = $this->sell($tenant, [$this->saleLine($rice, '8')], [$this->pay('cash', '6400')])->assertCreated()->json('data.id');

        $this->assertSame('0', $this->lotRemaining($tenant, 'LOT-000001'));
        $this->assertSame('7', $this->lotRemaining($tenant, 'LOT-000002'));

        $response = $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $response
            ->assertJsonPath('data.number', 'VTE-000001')
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Erreur de saisie')
            ->assertJsonPath('data.amount_paid', '0')
            ->assertJsonPath('data.balance_due', '0')
            ->assertJsonPath('data.can.cancel', false)
            ->assertJsonPath('data.can.collect', false)
            ->assertJsonPath('data.payments.0.status', 'voided')
            ->assertJsonPath('data.payments.0.amount', '6400')
            ->assertJsonPath('data.payments.0.void_reason', 'Vente annulée : Erreur de saisie');

        $this->assertSame('5', $this->lotRemaining($tenant, 'LOT-000001'));
        $this->assertSame('10', $this->lotRemaining($tenant, 'LOT-000002'));

        $journal = DB::table('stock_movements')->where('type', 'sale_cancellation')->pluck('quantity')->sort()->values()->all();

        $this->assertSame(['3.000', '5.000'], $journal);

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Encore'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'sale_not_validated');
    }

    public function test_only_owners_and_admins_can_cancel_a_sale_and_a_reason_is_required(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $id = $this->sell($tenant, [$this->saleLine($rice, '1')], [$this->pay('cash', '700')])->assertCreated()->json('data.id');

        $this->postJson(self::URL."/{$id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'Indiquez pourquoi vous annulez cette vente.']);

        foreach ([TenantRole::Seller, TenantRole::StockManager] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Test'])
                ->assertForbidden()
                ->assertJsonPath('message', 'Seuls le propriétaire et les administrateurs peuvent annuler une vente.');
        }

        $this->asMember($this->addMember($tenant, TenantRole::Admin), $tenant);

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();
    }

    public function test_a_cancelled_sale_is_no_longer_owed(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $kofi = $this->makeCustomer($tenant, 'Kofi', ['credit_limit' => '3000']);

        $id = $this->creditSale($tenant, $rice, $kofi);

        $this->getJson("/api/v1/customers/{$kofi->id}")->assertJsonPath('data.balance_due', '2100');

        $this->sell($tenant, [$this->saleLine($rice, '3')], [], ['customer_id' => $kofi->id])
            ->assertStatus(409)->assertJsonPath('code', 'credit_limit_exceeded');

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->getJson("/api/v1/customers/{$kofi->id}")->assertJsonPath('data.balance_due', '0');

        $this->creditSale($tenant, $rice, $kofi);
    }

    // ----------------------------------------------------------- Encaissement

    public function test_the_rest_of_a_credit_sale_can_be_collected_later(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $kofi = $this->makeCustomer($tenant, 'Kofi');
        $id = $this->creditSale($tenant, $rice, $kofi);

        $this->postJson(self::URL."/{$id}/payments", $this->pay('mtn_momo', '1000', 'MP2611.1234'))
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.amount_paid', '1000')
            ->assertJsonPath('data.balance_due', '1100')
            ->assertJsonPath('data.can.collect', true)
            ->assertJsonPath('data.payments.0.reference', 'MP2611.1234');

        $this->postJson(self::URL."/{$id}/payments", $this->pay('mtn_momo', '5000'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_exceeds_balance')
            ->assertJsonPath('message', 'Le paiement par MTN MoMo dépasse le montant restant à payer (1100 XOF).');

        $paid = $this->postJson(self::URL."/{$id}/payments", $this->pay('cash', '2000'))->assertOk();

        $paid
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.amount_paid', '2100')
            ->assertJsonPath('data.balance_due', '0')
            ->assertJsonPath('data.can.collect', false);

        $cash = collect($paid->json('data.payments'))->firstWhere('method', 'cash');

        $this->assertSame('1100', $cash['amount']);
        $this->assertSame('2000', $cash['tendered']);
        $this->assertSame('900', $cash['change_given']);

        $this->postJson(self::URL."/{$id}/payments", $this->pay('cash', '100'))
            ->assertStatus(409)->assertJsonPath('code', 'sale_already_paid');
    }

    public function test_collection_rules_roles_and_validation(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $kofi = $this->makeCustomer($tenant, 'Kofi');
        $id = $this->creditSale($tenant, $rice, $kofi);
        $url = self::URL."/{$id}/payments";

        $this->postJson($url, ['amount' => '100'])
            ->assertStatus(422)->assertJsonValidationErrors(['method' => 'Choisissez le mode de paiement.']);
        $this->postJson($url, ['method' => 'bitcoin', 'amount' => '100'])
            ->assertJsonValidationErrors(['method' => "Ce mode de paiement n'est pas valide."]);
        $this->postJson($url, ['method' => 'cash'])
            ->assertJsonValidationErrors(['amount' => 'Indiquez le montant du paiement.']);
        $this->postJson($url, $this->pay('cash', '100.5'))
            ->assertJsonValidationErrors(['amount' => 'Le montant est invalide : saisissez un montant entier, sans décimales.']);
        $this->postJson($url, $this->pay('cash', '0'))
            ->assertJsonValidationErrors(['amount' => 'Le montant du paiement doit être supérieur à zéro.']);

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);
        $this->postJson($url, $this->pay('cash', '500'))->assertOk();

        foreach ([TenantRole::StockManager, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->postJson($url, $this->pay('cash', '100'))
                ->assertForbidden()
                ->assertJsonPath('message', 'Seuls le propriétaire, les administrateurs et les vendeurs peuvent encaisser des paiements.');
        }

        $this->asMember($owner, $tenant);
        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->postJson($url, $this->pay('cash', '100'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'sale_not_validated');
    }

    // --------------------------------------------------- Annulation d'un paiement

    public function test_a_payment_can_be_voided_and_the_debt_is_recomputed(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $kofi = $this->makeCustomer($tenant, 'Kofi');
        $id = $this->creditSale($tenant, $rice, $kofi, '3', [$this->pay('mtn_momo', '1000')]);
        $this->postJson(self::URL."/{$id}/payments", $this->pay('cash', '500'))->assertOk();

        $payments = $this->getJson(self::URL."/{$id}")->json('data.payments');
        $this->assertCount(2, $payments);

        $voided = $this->postJson(self::URL."/{$id}/payments/{$payments[0]['id']}/void", ['reason' => 'Paiement non reçu'])->assertOk();

        $voided
            ->assertJsonPath('data.amount_paid', '500')
            ->assertJsonPath('data.balance_due', '1600')
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.payments.0.status', 'voided')
            ->assertJsonPath('data.payments.0.void_reason', 'Paiement non reçu')
            ->assertJsonPath('data.payments.1.status', 'valid');

        $this->postJson(self::URL."/{$id}/payments/{$payments[0]['id']}/void", ['reason' => 'Encore'])
            ->assertStatus(409)->assertJsonPath('code', 'payment_already_voided');

        $this->postJson(self::URL."/{$id}/payments/{$payments[1]['id']}/void", ['reason' => 'Erreur de caisse'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.balance_due', '2100');
    }

    public function test_voiding_a_payment_is_reserved_and_checked(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $kofi = $this->makeCustomer($tenant, 'Kofi');
        $first = $this->creditSale($tenant, $rice, $kofi, '1', [$this->pay('mtn_momo', '300')]);
        $second = $this->creditSale($tenant, $rice, $kofi, '1', [$this->pay('mtn_momo', '200')]);

        $payment = $this->getJson(self::URL."/{$first}")->json('data.payments.0.id');
        $other = $this->getJson(self::URL."/{$second}")->json('data.payments.0.id');

        $this->postJson(self::URL."/{$first}/payments/{$payment}/void", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'Indiquez pourquoi vous annulez ce paiement.']);

        $this->postJson(self::URL."/{$first}/payments/{$other}/void", ['reason' => 'Test'])->assertNotFound();

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);

        $this->postJson(self::URL."/{$first}/payments/{$payment}/void", ['reason' => 'Test'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Seuls le propriétaire et les administrateurs peuvent annuler un paiement.');
    }

    // ------------------------------------------------- Paiement global d'un client

    public function test_a_customer_payment_settles_the_oldest_sales_first(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $kofi = $this->makeCustomer($tenant, 'Kofi');
        $awa = $this->makeCustomer($tenant, 'Awa');

        $one = $this->creditSale($tenant, $rice, $kofi, '3');
        $two = $this->creditSale($tenant, $rice, $kofi, '1');
        $three = $this->creditSale($tenant, $rice, $kofi, '2');
        $this->creditSale($tenant, $rice, $awa, '1');

        $url = "/api/v1/customers/{$kofi->id}/payments";

        $response = $this->postJson($url, $this->pay('mtn_momo', '3000'))->assertOk();

        $response
            ->assertJsonPath('data.applied', '3000')
            ->assertJsonPath('data.change_given', '0')
            ->assertJsonPath('data.outstanding_after', '1200');

        $this->assertSame(['VTE-000001', 'VTE-000002', 'VTE-000003'], $response->json('data.sales.*.number'));
        $this->assertSame(['2100', '700', '200'], $response->json('data.sales.*.amount'));
        $this->assertSame(['paid', 'paid', 'partial'], $response->json('data.sales.*.payment_status'));

        $this->getJson(self::URL."/{$one}")->assertJsonPath('data.payment_status', 'paid');
        $this->getJson(self::URL."/{$two}")->assertJsonPath('data.payment_status', 'paid');
        $this->getJson(self::URL."/{$three}")
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.balance_due', '1200');

        $this->postJson($url, $this->pay('mtn_momo', '5000'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_exceeds_balance')
            ->assertJsonPath('message', 'Le paiement par MTN MoMo dépasse ce que ce client doit (1200 XOF).');

        $this->postJson($url, $this->pay('cash', '2000'))
            ->assertOk()
            ->assertJsonPath('data.applied', '1200')
            ->assertJsonPath('data.change_given', '800')
            ->assertJsonPath('data.outstanding_after', '0');

        $this->postJson($url, $this->pay('cash', '100'))
            ->assertStatus(409)->assertJsonPath('code', 'nothing_due');

        $this->getJson("/api/v1/customers/{$awa->id}")->assertJsonPath('data.balance_due', '700');

        $this->asMember($this->addMember($tenant, TenantRole::Viewer), $tenant);

        $this->postJson("/api/v1/customers/{$awa->id}/payments", $this->pay('cash', '100'))->assertForbidden();
    }

    // ----------------------------------------- Soldes, filtre, plafond de crédit

    public function test_customer_balances_and_the_balance_filter(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $kofi = $this->makeCustomer($tenant, 'Kofi');
        $awa = $this->makeCustomer($tenant, 'Awa');

        $this->creditSale($tenant, $rice, $kofi, '3');
        $this->creditSale($tenant, $rice, $awa, '1', [$this->pay('cash', '700')]);
        $this->sell($tenant, [$this->saleLine($rice, '1')], [$this->pay('cash', '700')])->assertCreated();

        $list = collect($this->getJson('/api/v1/customers')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame('2100', $list['Kofi']['balance_due']);
        $this->assertSame('0', $list['Awa']['balance_due']);

        $this->getJson(self::URL)->assertJsonPath('meta.total', 3);
        $this->getJson(self::URL.'?has_balance=1')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?has_balance=1&customer_id='.$kofi->id)->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?has_balance=1&customer_id='.$awa->id)->assertJsonPath('meta.total', 0);
    }

    public function test_collecting_frees_the_credit_limit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $kofi = $this->makeCustomer($tenant, 'Kofi', ['credit_limit' => '3000']);

        $id = $this->creditSale($tenant, $rice, $kofi);

        $this->sell($tenant, [$this->saleLine($rice, '3')], [], ['customer_id' => $kofi->id])
            ->assertStatus(409)->assertJsonPath('code', 'credit_limit_exceeded');

        $this->postJson(self::URL."/{$id}/payments", $this->pay('cash', '1500'))->assertOk();

        $this->creditSale($tenant, $rice, $kofi);

        $this->getJson("/api/v1/customers/{$kofi->id}")->assertJsonPath('data.balance_due', '2700');
    }

    // ------------------------------------------------ Garde-fous PostgreSQL

    public function test_the_database_protects_a_cancelled_sale(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $id = $this->sell($tenant, [$this->saleLine($rice, '1')], [$this->pay('cash', '700')])->assertCreated()->json('data.id');
        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->expectException(QueryException::class);

        DB::table('sales')->update(['notes' => 'modifié']);
    }

    public function test_the_database_protects_a_voided_payment(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->stockedRice($tenant);
        $id = $this->sell($tenant, [$this->saleLine($rice, '1')], [$this->pay('cash', '700')])->assertCreated()->json('data.id');
        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->expectException(QueryException::class);

        DB::table('sale_payments')->update(['void_reason' => 'modifié']);
    }
}