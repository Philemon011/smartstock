<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly SupplierService $suppliers)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Supplier::class);

        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : 'all';

        $search = trim((string) $request->query('search', ''));
        $like = addcslashes($search, '%_\\');

        $perPage = min(
            max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );

        $suppliers = Supplier::query()
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('name_key', 'like', '%'.addcslashes(Supplier::keyFor($search), '%_\\').'%')
                    ->orWhere('contact_name', 'ilike', "%{$like}%")
                    ->orWhere('phone', 'like', "%{$like}%")
                    ->orWhere('city', 'ilike', "%{$like}%"),
            ))
            ->orderBy('name_key')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return SupplierResource::collection($suppliers)->additional([
            'meta' => [
                'can' => [
                    'create' => app(CurrentTenant::class)->tenant()->isWritable()
                        && Gate::allows('create', Supplier::class),
                ],
            ],
        ]);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        Gate::authorize('view', $supplier);

        return SupplierResource::make($supplier);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        $supplier = $this->suppliers->create($request->validated());

        return SupplierResource::make($supplier)->response()->setStatusCode(201);
    }

    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        return SupplierResource::make($this->suppliers->update($supplier, $request->validated()));
    }

    public function destroy(Supplier $supplier): Response
    {
        Gate::authorize('delete', $supplier);

        $this->suppliers->delete($supplier);

        return response()->noContent();
    }

    public function activate(Supplier $supplier): SupplierResource
    {
        Gate::authorize('activate', $supplier);

        return SupplierResource::make($this->suppliers->activate($supplier));
    }

    public function deactivate(Supplier $supplier): SupplierResource
    {
        Gate::authorize('deactivate', $supplier);

        return SupplierResource::make($this->suppliers->deactivate($supplier));
    }
}