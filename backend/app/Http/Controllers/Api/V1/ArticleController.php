<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ArticleRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\ArticlePriceChange;
use App\Models\Category;
use App\Services\ArticleService;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ArticleController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    private const WITH = ['category', 'unit', 'packagings'];

    public function __construct(private readonly ArticleService $articles)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Article::class);

        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : 'all';

        $search = trim((string) $request->query('search', ''));
        $perPage = min(max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
        $categoryId = $request->query('category_id');

        $articles = Article::query()
            ->with(self::WITH)
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when(is_string($categoryId) && $categoryId !== '', fn ($query) => $query->whereIn(
                'category_id',
                Category::query()->where('id', $categoryId)->orWhere('parent_id', $categoryId)->pluck('id')->all(),
            ))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('name_key', 'like', '%'.addcslashes(Article::keyFor($search), '%_\\').'%')
                    ->orWhere('code', 'like', '%'.addcslashes(mb_strtoupper($search), '%_\\').'%')
                    ->orWhere('barcode', 'like', '%'.addcslashes(mb_strtoupper($search), '%_\\').'%'),
            ))
            ->orderBy('name_key')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return ArticleResource::collection($articles)->additional([
            'meta' => [
                'can' => [
                    'create' => app(CurrentTenant::class)->tenant()->isWritable()
                        && Gate::allows('create', Article::class),
                ],
            ],
        ]);
    }

    public function show(Article $article): ArticleResource
    {
        Gate::authorize('view', $article);

        return $this->resource($article);
    }

    public function store(ArticleRequest $request): JsonResponse
    {
        $article = $this->articles->create($request->validated());

        return $this->resource($article)->response()->setStatusCode(201);
    }

    public function update(ArticleRequest $request, Article $article): ArticleResource
    {
        return $this->resource($this->articles->update($article, $request->validated()));
    }

    public function destroy(Article $article): Response
    {
        Gate::authorize('delete', $article);

        $this->articles->delete($article);

        return response()->noContent();
    }

    public function activate(Article $article): ArticleResource
    {
        Gate::authorize('activate', $article);

        return $this->resource($this->articles->activate($article));
    }

    public function deactivate(Article $article): ArticleResource
    {
        Gate::authorize('deactivate', $article);

        return $this->resource($this->articles->deactivate($article));
    }

    public function priceHistory(Article $article): JsonResponse
    {
        Gate::authorize('view', $article);

        $currency = app(CurrentTenant::class)->tenant()->currency;

        $changes = ArticlePriceChange::query()
            ->where('article_id', $article->id)
            ->with('changedBy:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (ArticlePriceChange $change): array => [
                'id' => $change->id,
                'old_price' => $change->old_price === null ? null : Money::normalize((string) $change->old_price, $currency),
                'new_price' => Money::normalize((string) $change->new_price, $currency),
                'changed_by' => $change->changedBy?->name,
                'created_at' => $change->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $changes]);
    }

    private function resource(Article $article): ArticleResource
    {
        return ArticleResource::make($article->load(self::WITH));
    }
}