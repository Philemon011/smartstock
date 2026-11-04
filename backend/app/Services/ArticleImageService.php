<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ArticleImageService
{
    public function store(Article $article, UploadedFile $file): Article
    {
        $disk = Storage::disk(config('filesystems.files_disk'));
        $previous = $article->image_path;

        $path = $disk->putFileAs(
            "tenants/{$article->tenant_id}/articles/{$article->id}",
            $file,
            Str::lower((string) Str::ulid()).'.'.$file->extension(),
        );

        try {
            $article->forceFill(['image_path' => $path])->save();
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        if ($previous !== null) {
            $disk->delete($previous);
        }

        return $article;
    }

    public function remove(Article $article): Article
    {
        $path = $article->image_path;

        if ($path !== null) {
            $article->forceFill(['image_path' => null])->save();
            Storage::disk(config('filesystems.files_disk'))->delete($path);
        }

        return $article;
    }
}