<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockEntryStatus;
use App\Enums\StockExitReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockExitRequest;
use App\Http\Resources\StockExitResource;
use App\Models\StockExit;
use App\Services\StockExitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class StockExitController extends Controller
{
    use AuthorizesRequests;
    private const PER_PAGE = 20;

    public function __construct(private readonly StockExitService $exits)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockExit::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(StockEntryStatus::class)],
            'reason' => ['nullable', Rule::enum(StockExitReason::class)],
            'warehouse_id' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'status.enum' => "Ce statut n'est pas valide.",
            'reason.enum' => "Ce motif n'est pas valide.",
            'from.date_format' => 'La date de début est invalide.',
            'to.date_format' => 'La date de fin est invalide.',
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $paginator = StockExit::query()
            ->with(['warehouse:id,name', 'supplier:id,name'])
            ->withCount('lines')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['reason'] ?? null, fn ($q, $v) => $q->where('reason', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('exit_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('exit_date', '<=', $v))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $q->where(fn ($w) => $w
                    ->where('number', 'ilike', $like)
                    ->orWhere('reference', 'ilike', $like)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', $like)));
            })
            ->orderByDesc('exit_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => StockExitResource::collection($paginator->getCollection())->resolve($request),
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

    public function show(Request $request, StockExit $stockExit): JsonResponse
    {
        $this->authorize('view', $stockExit);

        return $this->respond($request, $this->load($stockExit));
    }

    public function store(StockExitRequest $request): JsonResponse
    {
        $this->authorize('create', StockExit::class);

        $exit = $this->exits->create($request->validated());

        return $this->respond($request, $exit, 201);
    }

    public function update(StockExitRequest $request, StockExit $stockExit): JsonResponse
    {
        $this->authorize('update', $stockExit);

        return $this->respond($request, $this->exits->update($stockExit, $request->validated()));
    }

    public function destroy(StockExit $stockExit): JsonResponse
    {
        $this->authorize('delete', $stockExit);

        $this->exits->delete($stockExit);

        return response()->json(null, 204);
    }

    public function validateExit(Request $request, StockExit $stockExit): JsonResponse
    {
        $this->authorize('validate', $stockExit);

        return $this->respond($request, $this->exits->validate($stockExit));
    }

    public function cancel(Request $request, StockExit $stockExit): JsonResponse
    {
        $this->authorize('cancel', $stockExit);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ], [
            'reason.required' => 'Indiquez pourquoi vous annulez cette sortie.',
            'reason.min' => 'Le motif doit contenir au moins 3 caractères.',
            'reason.max' => 'Le motif ne peut pas dépasser 300 caractères.',
        ]);

        return $this->respond($request, $this->exits->cancel($stockExit, trim($data['reason'])));
    }

    private function load(StockExit $exit): StockExit
    {
        return $exit->load([
            'warehouse', 'supplier', 'creator:id,name', 'validator:id,name', 'canceller:id,name',
            'lines.article.unit', 'lines.lot', 'lines.allocations.lot',
        ]);
    }

    private function respond(Request $request, StockExit $exit, int $status = 200): JsonResponse
    {
        return response()->json(
            ['data' => (new StockExitResource($exit))->resolve($request)],
            $status,
        );
    }
}