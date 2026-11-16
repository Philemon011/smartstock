<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockEntryResource;
use App\Models\Article;
use App\Models\StockEntry;
use App\Models\Warehouse;
use App\Services\AlertService;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const RECENT_DAYS = 30;

    public function __construct(private readonly AlertService $alerts)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $current = app(CurrentTenant::class);
        $tenant = $current->tenant();
        $role = $current->role();
        $decimals = Money::decimals($tenant->currency);

        $stockValue = null;
        $entries = null;
        $exits = null;

        if ($role->canViewCosts()) {
            $value = DB::table('stock_lots')
                ->where('tenant_id', $tenant->id)
                ->where('quantity_remaining', '>', 0)
                ->selectRaw('COALESCE(SUM(ROUND(quantity_remaining * unit_cost, ?)), 0) AS value', [$decimals])
                ->value('value');

            $stockValue = Money::normalize((string) $value, $tenant->currency);
        }

        if ($role->canManageStock()) {
            $since = Carbon::parse($this->alerts->today())->subDays(self::RECENT_DAYS)->toDateString();

            $recent30 = DB::table('stock_entries')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'validated')
                ->where('entry_date', '>=', $since)
                ->selectRaw('COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total')
                ->first();

            $latest = StockEntry::query()
                ->with(['warehouse:id,name', 'supplier:id,name'])
                ->withCount('lines')
                ->withSum('lines', 'line_total')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            $entries = [
                'count_30d' => (int) $recent30->count,
                'total_30d' => Money::normalize((string) $recent30->total, $tenant->currency),
                'recent' => StockEntryResource::collection($latest)->resolve($request),
            ];

                        $exitsLast30 = DB::table('stock_exits')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'validated')
                ->where('exit_date', '>=', $since)
                ->selectRaw('COUNT(*) AS count, COALESCE(SUM(total_cost), 0) AS total')
                ->first();

            $exits = [
                'count_30d' => (int) $exitsLast30->count,
                'total_30d' => Money::normalize((string) $exitsLast30->total, $tenant->currency),
            ];
        }

                $sales = null;

        if ($role->canSell()) {
            $today = $this->alerts->today();
            $monthStart = Carbon::parse($today)->startOfMonth()->toDateString();

            $period = fn (string $from) => DB::table('sales')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'validated')
                ->where('sale_date', '>=', $from)
                ->where('sale_date', '<=', $today)
                ->selectRaw('COUNT(*) AS count, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(total - cost_total), 0) AS margin')
                ->first();

            $day = $period($today);
            $month = $period($monthStart);

            $owed = DB::table('sales')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'validated')
                ->whereColumn('total', '>', 'amount_paid')
                ->selectRaw('COALESCE(SUM(total - amount_paid), 0) AS due, COUNT(*) AS sales_count, COUNT(DISTINCT customer_id) AS customers_count, MIN(sale_date) AS oldest')
                ->first();

            $sales = [
                'today' => [
                    'count' => (int) $day->count,
                    'total' => Money::normalize((string) $day->total, $tenant->currency),
                ],
                'month' => [
                    'count' => (int) $month->count,
                    'total' => Money::normalize((string) $month->total, $tenant->currency),
                    'margin' => $role->canViewCosts()
                        ? Money::normalize((string) $month->margin, $tenant->currency)
                        : null,
                ],
                'receivables' => [
                    'total_due' => Money::normalize((string) $owed->due, $tenant->currency),
                    'customers_count' => (int) $owed->customers_count,
                    'sales_count' => (int) $owed->sales_count,
                    'oldest_date' => $owed->oldest,
                ],
            ];
        }

        return response()->json([
            'data' => [
                'articles_active' => Article::query()->where('is_active', true)->count(),
                'warehouses_active' => Warehouse::query()->where('is_active', true)->count(),
                'alerts' => $this->alerts->summary(),
                'stock_value' => $stockValue,
                'entries' => $entries,
                'exits' => $exits,
                                'sales' => $sales,
            ],
        ]);
    }
}