<?php

namespace App\Http\Requests;

use App\Models\Article;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ArticleImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $article = $this->route('article');

        if ($article instanceof Article) {
            Gate::authorize('update', $article);
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Choisissez une photo.',
            'image.file' => "La photo n'a pas pu être envoyée. Elle dépasse peut-être 2 Mo.",
            'image.uploaded' => "La photo n'a pas pu être envoyée. Elle dépasse peut-être 2 Mo.",
            'image.mimes' => 'La photo doit être au format JPG, PNG ou WebP.',
            'image.max' => 'La photo ne peut pas dépasser 2 Mo.',
        ];
    }
}