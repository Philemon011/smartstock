<?php

namespace App\Http\Requests;

use App\Models\StockEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StockEntryDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('stock_entry');

        if ($entry instanceof StockEntry) {
            Gate::authorize('update', $entry);
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.required' => 'Choisissez le fichier du bon.',
            'document.file' => "Le fichier n'a pas pu être envoyé. Il dépasse peut-être 2 Mo.",
            'document.uploaded' => "Le fichier n'a pas pu être envoyé. Il dépasse peut-être 2 Mo.",
            'document.mimes' => 'Le bon doit être un PDF ou une image (JPG, PNG, WebP).',
            'document.max' => 'Le fichier ne peut pas dépasser 2 Mo.',
        ];
    }
}