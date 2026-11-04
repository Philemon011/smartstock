<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\StockLot;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ArticleStockController extends Controller
{
    public function show(Article $article): JsonResponse
    {
        Gate::authorize('view', $article);

        $current = app(CurrentTenant::class);
        $tenant = $current->tenant();
        $showCosts = $current->role()->canViewCosts();
        $decimals = (bool) $article->unit->allows_decimals;
        $today = now($tenant->timezone)->startOfDay();

        $lots = StockLot::query()
            ->where('article_id', $article->id)
            ->where('quantity_remaining', '>', 0)
            ->with('warehouse:id,name')
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        $sum = fn ($items): string => $items->reduce(
            fn (string $carry, StockLot $lot): string => bcadd($carry, (string) $lot->quantity_remaining, 3),
            '0',
        );

        return response()->json([
            'data' => [
                'article_id' => $article->id,
                'unit' => ['symbol' => $article->unit->symbol, 'allows_decimals' => $decimals],
                'total' => Quantity::format($sum($lots), $decimals),
                'warehouses' => $lots->groupBy('warehouse_id')->map(fn ($group): array => [
                    'warehouse' => ['id' => $group->first()->warehouse->id, 'name' => $group->first()->warehouse->name],
                    'quantity' => Quantity::format($sum($group), $decimals),
                ])->sortBy(fn (array $row) => $row['warehouse']['name'])->values(),
                'lots' => $lots->map(function (StockLot $lot) use ($showCosts, $decimals, $today, $tenant): array {
                    $row = [
                        'id' => $lot->id,
                        'code' => $lot->code,
                        'lot_number' => $lot->lot_number,
                        'warehouse' => ['id' => $lot->warehouse->id, 'name' => $lot->warehouse->name],
                        'received_date' => $lot->received_date->toDateString(),
                        'expiry_date' => $lot->expiry_date?->toDateString(),
                        'days_to_expiry' => $lot->expiry_date === null
                            ? null
                            : (int) $today->diffInDays($lot->expiry_date->copy()->startOfDay(), false),
                        'quantity_initial' => Quantity::format((string) $lot->quantity_initial, $decimals),
                        'quantity_remaining' => Quantity::format((string) $lot->quantity_remaining, $decimals),
                    ];

                    if ($showCosts) {
                        $row['unit_cost'] = rtrim(rtrim((string) $lot->unit_cost, '0'), '.');
                        $row['value'] = Money::normalize(
                            Money::round(bcmul((string) $lot->quantity_remaining, (string) $lot->unit_cost, 9), Money::decimals($tenant->currency)),
                            $tenant->currency,
                        );
                    }

                    return $row;
                })->values(),
            ],
        ]);
    }
}