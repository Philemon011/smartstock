<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockEntryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\StockTransferService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StockTransferController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly StockTransferService $transfers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockTransfer::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(StockEntryStatus::class)],
            'warehouse_id' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'status.enum' => "Ce statut n'est pas valide.",
            'from.date_format' => 'La date de début est invalide.',
            'to.date_format' => 'La date de fin est invalide.',
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $paginator = StockTransfer::query()
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name'])
            ->withCount('lines')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where(
                fn ($w) => $w->where('from_warehouse_id', $v)->orWhere('to_warehouse_id', $v),
            ))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('transfer_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('transfer_date', '<=', $v))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $q->where(fn ($w) => $w->where('number', 'ilike', $like)->orWhere('reference', 'ilike', $like));
            })
            ->orderByDesc('transfer_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => StockTransferResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'can' => [
                    'create' => app(CurrentTenant::class)->tenant()->isWritable()
                        && Gate::allows('create', StockTransfer::class),
                ],
            ],
        ]);
    }

    public function show(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        Gate::authorize('view', $stockTransfer);

        return $this->respond($request, $this->load($stockTransfer));
    }

    public function store(StockTransferRequest $request): JsonResponse
    {
        return $this->respond($request, $this->transfers->create($request->validated()), 201);
    }

    public function update(StockTransferRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->respond($request, $this->transfers->update($stockTransfer, $request->validated()));
    }

    public function destroy(StockTransfer $stockTransfer): JsonResponse
    {
        Gate::authorize('delete', $stockTransfer);

        $this->transfers->delete($stockTransfer);

        return response()->json(null, 204);
    }

    public function validateTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        Gate::authorize('validate', $stockTransfer);

        return $this->respond($request, $this->transfers->validate($stockTransfer));
    }

    public function cancel(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        Gate::authorize('cancel', $stockTransfer);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ], [
            'reason.required' => 'Indiquez pourquoi vous annulez ce transfert.',
            'reason.min' => 'Le motif doit contenir au moins 3 caractères.',
            'reason.max' => 'Le motif ne peut pas dépasser 300 caractères.',
        ]);

        return $this->respond($request, $this->transfers->cancel($stockTransfer, trim($data['reason'])));
    }

    private function load(StockTransfer $transfer): StockTransfer
    {
        return $transfer->load([
            'fromWarehouse', 'toWarehouse', 'creator:id,name', 'validator:id,name', 'canceller:id,name',
            'lines.article.unit', 'lines.lot', 'lines.allocations.sourceLot', 'lines.allocations.destinationLot',
        ]);
    }

    private function respond(Request $request, StockTransfer $transfer, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new StockTransferResource($transfer))->resolve($request)], $status);
    }
}