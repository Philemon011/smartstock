<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockEntryReason;
use App\Enums\StockEntryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockEntryRequest;
use App\Http\Resources\StockEntryResource;
use App\Models\StockEntry;
use App\Services\StockEntryService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class StockEntryController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly StockEntryService $entries)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockEntry::class);

        $status = StockEntryStatus::tryFrom((string) $request->query('status'));
        $reason = StockEntryReason::tryFrom((string) $request->query('reason'));
        $warehouseId = $request->query('warehouse_id');
        $supplierId = $request->query('supplier_id');
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $search = trim((string) $request->query('search', ''));
        $like = addcslashes($search, '%_\\');

        $perPage = min(max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $entries = StockEntry::query()
            ->with(['warehouse:id,name', 'supplier:id,name'])
            ->withCount('lines')
            ->withSum('lines', 'line_total')
            ->when($status, fn ($query, StockEntryStatus $value) => $query->where('status', $value->value))
            ->when($reason, fn ($query, StockEntryReason $value) => $query->where('reason', $value->value))
            ->when(is_string($warehouseId) && $warehouseId !== '', fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when(is_string($supplierId) && $supplierId !== '', fn ($query) => $query->where('supplier_id', $supplierId))
            ->when($from, fn ($query, string $date) => $query->where('entry_date', '>=', $date))
            ->when($to, fn ($query, string $date) => $query->where('entry_date', '<=', $date))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('number', 'ilike', "%{$like}%")
                    ->orWhere('reference', 'ilike', "%{$like}%")
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'ilike', "%{$like}%")),
            ))
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return StockEntryResource::collection($entries)->additional([
            'meta' => [
                'can' => [
                    'create' => app(CurrentTenant::class)->tenant()->isWritable()
                        && Gate::allows('create', StockEntry::class),
                ],
            ],
        ]);
    }

    public function show(StockEntry $stockEntry): StockEntryResource
    {
        Gate::authorize('view', $stockEntry);

        return $this->detail($stockEntry);
    }

    public function store(StockEntryRequest $request): JsonResponse
    {
        $entry = $this->entries->create($request->validated());

        return $this->detail($entry)->response()->setStatusCode(201);
    }

    public function update(StockEntryRequest $request, StockEntry $stockEntry): StockEntryResource
    {
        return $this->detail($this->entries->update($stockEntry, $request->validated()));
    }

    public function destroy(StockEntry $stockEntry): Response
    {
        Gate::authorize('delete', $stockEntry);

        $this->entries->delete($stockEntry);

        return response()->noContent();
    }

    public function validateEntry(StockEntry $stockEntry): StockEntryResource
    {
        Gate::authorize('validate', $stockEntry);

        return $this->detail($this->entries->validate($stockEntry));
    }

    public function cancel(Request $request, StockEntry $stockEntry): StockEntryResource
    {
        Gate::authorize('cancel', $stockEntry);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:300']],
            [
                'reason.required' => "Indiquez pourquoi vous annulez cette entrée.",
                'reason.string' => "Le motif d'annulation n'est pas valide.",
                'reason.min' => "Le motif d'annulation doit contenir au moins 3 caractères.",
                'reason.max' => "Le motif d'annulation ne peut pas dépasser 300 caractères.",
            ],
        );

        return $this->detail($this->entries->cancel($stockEntry, trim($data['reason'])));
    }

    private function detail(StockEntry $entry): StockEntryResource
    {
        return StockEntryResource::make($entry->load([
            'warehouse',
            'supplier',
            'creator:id,name',
            'validator:id,name',
            'canceller:id,name',
            'lines' => fn ($query) => $query->orderBy('position'),
            'lines.article.unit',
            'lines.lot',
        ]));
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}