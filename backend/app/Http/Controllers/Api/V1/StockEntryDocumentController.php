<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockEntryDocumentRequest;
use App\Http\Resources\StockEntryResource;
use App\Models\StockEntry;
use App\Services\StockEntryDocumentService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockEntryDocumentController extends Controller
{
    public function __construct(private readonly StockEntryDocumentService $documents)
    {
    }

    public function store(StockEntryDocumentRequest $request, StockEntry $stockEntry): StockEntryResource
    {
        $this->documents->store($stockEntry, $request->file('document'));

        return $this->resource($stockEntry);
    }

    public function destroy(StockEntry $stockEntry): Response
    {
        Gate::authorize('update', $stockEntry);

        $this->documents->remove($stockEntry);

        return response()->noContent();
    }

    /**
     * Lien signé : l'accès est contrôlé par la signature (un lien ou une balise ne peut pas envoyer d'en-tête personnalisé).
     */
    public function show(string $entry): StreamedResponse
    {
        $record = StockEntry::withoutGlobalScopes()->find($entry);

        abort_if($record === null || $record->document_path === null, 404);

        $disk = Storage::disk(config('filesystems.files_disk'));

        abort_unless($disk->exists($record->document_path), 404);

        return $disk->response($record->document_path, $record->document_name, ['Cache-Control' => 'private, max-age=3600']);
    }

    private function resource(StockEntry $entry): StockEntryResource
    {
        return StockEntryResource::make($entry->load([
            'warehouse',
            'supplier',
            'lines' => fn ($query) => $query->orderBy('position'),
            'lines.article.unit',
            'lines.lot',
        ]));
    }
}