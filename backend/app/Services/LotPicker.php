<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Article;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Support\Quantity;

class LotPicker
{
    /**
     * Choisit les lots à prélever, le lot qui expire en premier d'abord. Les lots expirés ne sont jamais pris.
     * $reserved garde les quantités déjà prévues par les lignes précédentes de la même vente.
     *
     * @param  array<string, string>  $reserved
     * @return list<array{0: StockLot, 1: string}>
     */
    public function pick(Article $article, Warehouse $warehouse, string $needed, string $today, array &$reserved): array
    {
        $lots = StockLot::query()
            ->where('article_id', $article->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('quantity_remaining', '>', 0)
            ->whereNull('voided_at')
            ->where(fn ($query) => $query->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today))
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('received_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $free = [];
        $available = '0';

        foreach ($lots as $lot) {
            $left = bcsub((string) $lot->quantity_remaining, $reserved[$lot->id] ?? '0', 3);

            if (bccomp($left, '0', 3) > 0) {
                $free[] = [$lot, $left];
                $available = bcadd($available, $left, 3);
            }
        }

        if (bccomp($available, $needed, 3) < 0) {
            $decimals = (bool) $article->unit->allows_decimals;
            $symbol = $article->unit->symbol;
            $format = fn (string $value): string => Quantity::format($value, $decimals);

            throw new BusinessRuleException(
                "Stock insuffisant pour « {$article->name} » dans l'entrepôt « {$warehouse->name} » : {$format($available)} {$symbol} disponible(s) (hors lots expirés), {$format($needed)} {$symbol} demandé(s).",
                'insufficient_stock',
            );
        }

        $picked = [];
        $remaining = $needed;

        foreach ($free as [$lot, $left]) {
            if (bccomp($remaining, '0', 3) <= 0) {
                break;
            }

            $take = bccomp($left, $remaining, 3) <= 0 ? $left : $remaining;

            $picked[] = [$lot, $take];
            $reserved[$lot->id] = bcadd($reserved[$lot->id] ?? '0', $take, 3);
            $remaining = bcsub($remaining, $take, 3);
        }

        return $picked;
    }
}