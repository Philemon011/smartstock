<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Category::class);

        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : 'all';

        $search = trim((string) $request->query('search', ''));

        $matches = Category::query()
            ->withCount('children')
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($search !== '', fn ($query) => $query->where(
                'name_key',
                'like',
                '%'.addcslashes(Category::keyFor($search), '%_\\').'%',
            ))
            ->get();

        $missingParentIds = $matches->pluck('parent_id')->filter()->unique()->diff($matches->pluck('id'));

        $parents = $missingParentIds->isEmpty()
            ? collect()
            : Category::query()->withCount('children')->whereIn('id', $missingParentIds->all())->get();

        return CategoryResource::collection($this->inTreeOrder($matches->concat($parents)))->additional([
            'meta' => [
                'can' => [
                    'create' => app(CurrentTenant::class)->tenant()->isWritable()
                        && Gate::allows('create', Category::class),
                ],
            ],
        ]);
    }

    public function show(Category $category): CategoryResource
    {
        Gate::authorize('view', $category);

        return $this->resource($category);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->validated());

        return $this->resource($category)->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        return $this->resource($this->categories->update($category, $request->validated()));
    }

    public function destroy(Category $category): Response
    {
        Gate::authorize('delete', $category);

        $this->categories->delete($category);

        return response()->noContent();
    }

    public function activate(Category $category): CategoryResource
    {
        Gate::authorize('activate', $category);

        return $this->resource($this->categories->activate($category));
    }

    public function deactivate(Category $category): CategoryResource
    {
        Gate::authorize('deactivate', $category);

        return $this->resource($this->categories->deactivate($category));
    }

    private function resource(Category $category): CategoryResource
    {
        return CategoryResource::make($category->loadCount('children'));
    }

    /**
     * Les catégories principales par ordre alphabétique, chacune suivie de ses sous-catégories.
     *
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    private function inTreeOrder(Collection $categories): Collection
    {
        $childrenByParent = $categories->whereNotNull('parent_id')->groupBy('parent_id');

        return $categories
            ->whereNull('parent_id')
            ->sortBy('name_key')
            ->flatMap(fn (Category $root): Collection => collect([$root])->concat(
                ($childrenByParent->get($root->id) ?? collect())->sortBy('name_key'),
            ))
            ->values();
    }
}