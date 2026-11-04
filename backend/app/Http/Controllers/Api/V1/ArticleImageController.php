<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ArticleImageRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Services\ArticleImageService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArticleImageController extends Controller
{
    public function __construct(private readonly ArticleImageService $images)
    {
    }

    public function store(ArticleImageRequest $request, Article $article): ArticleResource
    {
        $this->images->store($article, $request->file('image'));

        return ArticleResource::make($article->load(['category', 'unit', 'packagings']));
    }

    public function destroy(Article $article): Response
    {
        Gate::authorize('update', $article);

        $this->images->remove($article);

        return response()->noContent();
    }

    /**
     * Lien signé : l'accès est contrôlé par la signature, pas par la session (une balise <img> n'envoie pas d'en-tête personnalisé).
     */
    public function show(string $article): StreamedResponse
    {
        $record = Article::withoutGlobalScopes()->find($article);

        abort_if($record === null || $record->image_path === null, 404);

        $disk = Storage::disk(config('filesystems.files_disk'));

        abort_unless($disk->exists($record->image_path), 404);

        return $disk->response($record->image_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}