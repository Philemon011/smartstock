<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\Warehouse;
use App\Services\AlertService;
use App\Support\Quantity;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StockAvailabilityController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    public function __construct(private readonly AlertService $alerts)
    {
    }

    /**
     * Les articles qui ont du stock dans un entrepôt.
     * « sellable » : lots valables (ni expirés ni annulés). « expired » : lots expirés seulement (sortie pour péremption).
     */
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Article::class);

        $data = $request->validate([
            'warehouse_id' => ['required', 'string', 'size:26'],
            'mode' => ['nullable', 'in:sellable,expired'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer'],
        ], [
            'warehouse_id.required' => 'Choisissez un entrepôt.',
            'warehouse_id.size' => 'Cet entrepôt est introuvable.',
            'mode.in' => 'Le type de stock est invalide.',
        ]);

        $warehouse = Warehouse::query()->find($data['warehouse_id']);

        if ($warehouse === null) {
            throw ValidationException::withMessages(['warehouse_id' => 'Cet entrepôt est introuvable.']);
        }

        $tenantId = app(CurrentTenant::class)->id();
        $today = $this->alerts->today();
        $expired = ($data['mode'] ?? 'sellable') === 'expired';
        $perPage = min(max((int) ($data['per_page'] ?? self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $lots = fn () => DB::table('stock_lots')
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouse->id)
            ->where('quantity_remaining', '>', 0)
            ->whereNull('voided_at')
            ->when(
                $expired,
                fn ($query) => $query->where('expiry_date', '<', $today),
                fn ($query) => $query->where(
                    fn ($inner) => $inner->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today),
                ),
            );

        $search = trim((string) ($data['search'] ?? ''));
        $like = addcslashes($search, '%_\\');

        $paginator = Article::query()
            ->with(['category', 'unit', 'packagings'])
            ->where('is_active', true)
            ->whereIn('id', $lots()->select('article_id'))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('name_key', 'like', '%'.addcslashes(Article::keyFor($search), '%_\\').'%')
                    ->orWhere('code', 'ilike', "%{$like}%")
                    ->orWhere('barcode', 'like', "%{$like}%"),
            ))
            ->orderBy('name_key')
            ->orderBy('id')
            ->paginate($perPage);

        $ids = $paginator->getCollection()->pluck('id')->all();

        $available = $ids === []
            ? []
            : $lots()
                ->whereIn('article_id', $ids)
                ->groupBy('article_id')
                ->selectRaw('article_id, SUM(quantity_remaining) AS available')
                ->pluck('available', 'article_id')
                ->all();

        $items = $paginator->getCollection()->map(fn (Article $article): array => array_merge(
            ArticleResource::make($article)->resolve($request),
            ['available' => Quantity::format((string) ($available[$article->id] ?? '0'), (bool) $article->unit->allows_decimals)],
        ))->all();

        return response()->json([
            'data' => $items,
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