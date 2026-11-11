<?php

namespace Tests\Concerns;

use App\Models\Article;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithSales
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function saleLine(Article $article, string $quantity, array $overrides = []): array
    {
        return array_merge(['article_id' => $article->id, 'entered_quantity' => $quantity], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function pay(string $method, string $amount, ?string $reference = null): array
    {
        return ['method' => $method, 'amount' => $amount, 'reference' => $reference];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, mixed>  $overrides
     */
    protected function sell(Tenant $tenant, array $lines, array $payments = [], array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/sales', array_merge([
            'sale_date' => $this->today($tenant),
            'warehouse_id' => $this->mainWarehouse($tenant)->id,
            'lines' => $lines,
            'payments' => $payments,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeCustomer(Tenant $tenant, string $name, array $attributes = []): Customer
    {
        $customer = (new Customer)->forceFill(array_merge(['tenant_id' => $tenant->id, 'name' => $name], $attributes));
        $customer->save();

        return $customer;
    }

    protected function lotRemaining(Tenant $tenant, string $code): string
    {
        $value = (string) DB::table('stock_lots')->where('tenant_id', $tenant->id)->where('code', $code)->value('quantity_remaining');

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}