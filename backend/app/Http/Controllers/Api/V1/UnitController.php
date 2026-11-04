<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Services\UnitService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class UnitController extends Controller
{
    public function __construct(private readonly UnitService $units)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Unit::class);

        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : 'all';

        $search = trim((string) $request->query('search', ''));
        $like = addcslashes(Unit::keyFor($search), '%_\\');

        $units = Unit::query()
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('name_key', 'like', "%{$like}%")
                    ->orWhere('symbol_key', 'like', '%'.addcslashes(Unit::symbolKey($search), '%_\\').'%'),
            ))
            ->orderBy('name_key')
            ->get();

        return UnitResource::collection($units)->additional([
            'meta' => [
                'can' => [
                    'create' => app(CurrentTenant::class)->tenant()->isWritable()
                        && Gate::allows('create', Unit::class),
                ],
            ],
        ]);
    }

    public function show(Unit $unit): UnitResource
    {
        Gate::authorize('view', $unit);

        return UnitResource::make($unit);
    }

    public function store(UnitRequest $request): JsonResponse
    {
        $unit = $this->units->create($request->validated());

        return UnitResource::make($unit)->response()->setStatusCode(201);
    }

    public function update(UnitRequest $request, Unit $unit): UnitResource
    {
        return UnitResource::make($this->units->update($unit, $request->validated()));
    }

    public function destroy(Unit $unit): Response
    {
        Gate::authorize('delete', $unit);

        $this->units->delete($unit);

        return response()->noContent();
    }

    public function activate(Unit $unit): UnitResource
    {
        Gate::authorize('activate', $unit);

        return UnitResource::make($this->units->activate($unit));
    }

    public function deactivate(Unit $unit): UnitResource
    {
        Gate::authorize('deactivate', $unit);

        return UnitResource::make($this->units->deactivate($unit));
    }
}