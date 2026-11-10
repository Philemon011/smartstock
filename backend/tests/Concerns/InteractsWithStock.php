<?php

namespace Tests\Concerns;

use App\Models\Article;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\SequenceGenerator;

trait InteractsWithStock
{
    protected function unit(Tenant $tenant, string $symbol): Unit
    {
        return Unit::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('symbol_key', Unit::symbolKey($symbol))
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function article(Tenant $tenant, string $name, string $unitSymbol = 'sac', array $attributes = []): Article
    {
        $article = (new Article)->forceFill(array_merge([
            'tenant_id' => $tenant->id,
            'code' => sprintf('ART-%06d', app(SequenceGenerator::class)->next($tenant->id, 'article')),
            'name' => $name,
            'unit_id' => $this->unit($tenant, $unitSymbol)->id,
            'sale_price' => '500',
        ], $attributes));
        $article->save();

        return $article;
    }

    protected function mainWarehouse(Tenant $tenant): Warehouse
    {
        return Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_main', true)->firstOrFail();
    }

    /**
     * Le jour de l'entreprise, décalé d'un nombre de jours (négatif pour le passé).
     */
    protected function today(Tenant $tenant, int $offsetDays = 0): string
    {
        return now($tenant->timezone)->addDays($offsetDays)->toDateString();
    }

    /**
     * Reçoit du stock par l'API : une entrée « stock initial » créée puis validée.
     * La personne doit déjà être connectée avec asMember().
     */
    protected function receive(
        Tenant $tenant,
        Article $article,
        string $quantity,
        string $price = '500',
        ?string $expiry = null,
        ?string $entryDate = null,
        ?string $warehouseId = null,
    ): string {
        $id = $this->postJson('/api/v1/stock-entries', [
            'reason' => 'opening_stock',
            'entry_date' => $entryDate ?? $this->today($tenant),
            'warehouse_id' => $warehouseId ?? $this->mainWarehouse($tenant)->id,
            'lines' => [[
                'article_id' => $article->id,
                'entered_quantity' => $quantity,
                'unit_price' => $price,
                'expiry_date' => $expiry,
            ]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/stock-entries/{$id}/validate")->assertOk();

        return $id;
    }
}