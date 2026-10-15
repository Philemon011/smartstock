<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use App\Support\Tenancy\CurrentTenant;

class WarehouseController extends Controller
{
    public function __construct(private readonly WarehouseService $warehouses)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Warehouse::class);

        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : 'all';

        $search = trim((string) $request->query('search', ''));
        $like = addcslashes($search, '%_\\');

        $warehouses = Warehouse::query()
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('name_key', 'like', '%'.addcslashes(Warehouse::keyFor($search), '%_\\').'%')
                    ->orWhere('code', 'like', '%'.mb_strtoupper($like).'%')
                    ->orWhere('city', 'ilike', "%{$like}%"),
            ))
            ->orderByDesc('is_main')
            ->orderBy('name_key')
            ->get();

                return WarehouseResource::collection($warehouses)->additional([
            'meta' => [
                'can' => [
                    'create' => app(CurrentTenant::class)->tenant()->isWritable()
                        && Gate::allows('create', Warehouse::class),
                ],
            ],
        ]);
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        Gate::authorize('view', $warehouse);

        return WarehouseResource::make($warehouse);
    }

    public function store(WarehouseRequest $request): JsonResponse
    {
        $warehouse = $this->warehouses->create($request->validated());

        return WarehouseResource::make($warehouse)->response()->setStatusCode(201);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        return WarehouseResource::make($this->warehouses->update($warehouse, $request->validated()));
    }

    public function destroy(Warehouse $warehouse): Response
    {
        Gate::authorize('delete', $warehouse);

        $this->warehouses->delete($warehouse);

        return response()->noContent();
    }

    public function setMain(Warehouse $warehouse): WarehouseResource
    {
        Gate::authorize('setMain', $warehouse);

        return WarehouseResource::make($this->warehouses->setMain($warehouse));
    }

    public function activate(Warehouse $warehouse): WarehouseResource
    {
        Gate::authorize('activate', $warehouse);

        return WarehouseResource::make($this->warehouses->activate($warehouse));
    }

    public function deactivate(Warehouse $warehouse): WarehouseResource
    {
        Gate::authorize('deactivate', $warehouse);

        return WarehouseResource::make($this->warehouses->deactivate($warehouse));
    }
}