<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AlertService;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AlertController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly AlertService $alerts)
    {
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->alerts->summary()]);
    }

    public function index(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', '');

        if (! in_array($type, AlertService::TYPES, true)) {
            throw ValidationException::withMessages(['type' => "Ce type d'alerte n'est pas valide."]);
        }

        $perPage = min(max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $current = app(CurrentTenant::class);
        $currency = $current->tenant()->currency;
        $showCosts = $current->role()->canViewCosts();
        $today = Carbon::parse($this->alerts->today());

        if (in_array($type, ['expired', 'expiring'], true)) {
            $query = $type === 'expired' ? $this->alerts->expiredLots() : $this->alerts->expiringLots();

            $paginator = $query
                ->select([
                    'l.id as lot_id', 'l.code as lot_code', 'l.lot_number', 'l.expiry_date',
                    'l.quantity_remaining', 'l.unit_cost', 'w.name as warehouse_name',
                    'a.id as article_id', 'a.code as article_code', 'a.name as article_name',
                    'u.symbol as unit_symbol', 'u.allows_decimals',
                ])
                ->orderBy('l.expiry_date')
                ->orderBy('a.name_key')
                ->orderBy('l.id')
                ->paginate($perPage);

            $rows = collect($paginator->items())->map(function (object $row) use ($today, $showCosts, $currency): array {
                $decimals = (bool) $row->allows_decimals;
                $lot = [
                    'id' => $row->lot_id,
                    'code' => $row->lot_code,
                    'lot_number' => $row->lot_number,
                    'warehouse' => $row->warehouse_name,
                    'expiry_date' => $row->expiry_date,
                    'days_to_expiry' => (int) $today->diffInDays(Carbon::parse($row->expiry_date), false),
                    'quantity' => Quantity::format((string) $row->quantity_remaining, $decimals),
                ];

                if ($showCosts) {
                    $lot['value'] = Money::normalize(
                        Money::round(
                            bcmul((string) $row->quantity_remaining, (string) $row->unit_cost, 9),
                            Money::decimals($currency),
                        ),
                        $currency,
                    );
                }

                return [
                    'id' => $row->lot_id,
                    'article' => $this->article($row),
                    'lot' => $lot,
                    'stock' => null,
                ];
            })->all();

            return $this->page($paginator, $rows);
        }

        $query = $type === 'low_stock' ? $this->alerts->lowStockArticles() : $this->alerts->outOfStockArticles();

        $query = $type === 'low_stock'
            ? $query->orderByRaw('COALESCE(s.total, 0) / a.min_stock')
            : $query;

        $paginator = $query
            ->select([
                'a.id as article_id', 'a.code as article_code', 'a.name as article_name', 'a.min_stock',
                's.total', 's.lots_count', 'u.symbol as unit_symbol', 'u.allows_decimals',
            ])
            ->orderBy('a.name_key')
            ->orderBy('a.id')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(function (object $row): array {
            $decimals = (bool) $row->allows_decimals;

            return [
                'id' => $row->article_id,
                'article' => $this->article($row),
                'lot' => null,
                'stock' => [
                    'quantity' => Quantity::format((string) ($row->total ?? '0'), $decimals),
                    'minimum' => Quantity::format((string) $row->min_stock, $decimals),
                    'ever_stocked' => $row->lots_count !== null,
                ],
            ];
        })->all();

        return $this->page($paginator, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function article(object $row): array
    {
        return [
            'id' => $row->article_id,
            'code' => $row->article_code,
            'name' => $row->article_name,
            'unit' => ['symbol' => $row->unit_symbol, 'allows_decimals' => (bool) $row->allows_decimals],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function page(LengthAwarePaginator $paginator, array $rows): JsonResponse
    {
        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
}