<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\StockEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StockEntryDocumentService
{
    public function store(StockEntry $entry, UploadedFile $file): StockEntry
    {
        $this->assertNotCancelled($entry);

        $disk = Storage::disk(config('filesystems.files_disk'));
        $previous = $entry->document_path;

        $path = $disk->putFileAs(
            "tenants/{$entry->tenant_id}/entries/{$entry->id}",
            $file,
            Str::lower((string) Str::ulid()).'.'.$file->extension(),
        );

        try {
            $entry->forceFill([
                'document_path' => $path,
                'document_name' => Str::limit(basename($file->getClientOriginalName()), 140, ''),
            ])->save();
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        if ($previous !== null) {
            $disk->delete($previous);
        }

        return $entry;
    }

    public function remove(StockEntry $entry): StockEntry
    {
        $this->assertNotCancelled($entry);

        $path = $entry->document_path;

        if ($path !== null) {
            $entry->forceFill(['document_path' => null, 'document_name' => null])->save();
            Storage::disk(config('filesystems.files_disk'))->delete($path);
        }

        return $entry;
    }

    private function assertNotCancelled(StockEntry $entry): void
    {
        if ($entry->status->value === 'cancelled') {
            throw new BusinessRuleException(
                'Cette entrée est annulée : son bon ne peut plus être modifié.',
                'entry_cancelled',
            );
        }
    }
}