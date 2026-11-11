<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Enums\StockEntryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly SaleService $sales)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Sale::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(StockEntryStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'customer_id' => ['nullable', 'string', 'size:26'],
            'warehouse_id' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
                        'has_balance' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer'],
        ], [
            'status.enum' => "Ce statut n'est pas valide.",
            'payment_status.enum' => "Ce statut de paiement n'est pas valide.",
            'from.date_format' => 'La date de début est invalide.',
            'to.date_format' => 'La date de fin est invalide.',
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = min(max((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $sales = Sale::query()
            ->with(['warehouse:id,name', 'customer:id,name,phone'])
            ->withCount('lines')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sale_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sale_date', '<=', $v))
            ->when($request->boolean('has_balance'), fn ($q) => $q->where('status', 'validated')->whereColumn('total', '>', 'amount_paid'))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $keyLike = '%'.addcslashes(Customer::keyFor($search), '%_\\').'%';

                $q->where(fn ($w) => $w
                    ->where('number', 'ilike', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name_key', 'like', $keyLike)->orWhere('phone', 'like', $like)));
            })
            ->orderByDesc('sale_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return SaleResource::collection($sales)->additional([
            'meta' => [
                'can' => [
                    'create' => app(CurrentTenant::class)->tenant()->isWritable()
                        && Gate::allows('create', Sale::class),
                ],
            ],
        ]);
    }

    public function show(Sale $sale): SaleResource
    {
        Gate::authorize('view', $sale);

        return SaleResource::make($sale->load([
            'warehouse', 'customer', 'creator:id,name', 'canceller:id,name',
            'lines.article.unit', 'lines.allocations.lot', 'payments.creator:id,name',
        ]));
    }

    public function store(SaleRequest $request): JsonResponse
    {
        $sale = $this->sales->create($request->validated());

        return SaleResource::make($sale)->response()->setStatusCode(201);
    }

        public function cancel(Request $request, Sale $sale): SaleResource
    {
        Gate::authorize('cancel', $sale);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:280'],
        ], [
            'reason.required' => 'Indiquez pourquoi vous annulez cette vente.',
            'reason.min' => 'Le motif doit contenir au moins 3 caractères.',
            'reason.max' => 'Le motif ne peut pas dépasser 280 caractères.',
        ]);

        return SaleResource::make($this->sales->cancel($sale, trim($data['reason'])));
    }
}