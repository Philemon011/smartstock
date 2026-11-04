<?php

namespace App\Http\Resources;

use App\Enums\StockEntryStatus;
use App\Models\StockEntryLine;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * @mixin \App\Models\StockEntry
 */
class StockEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = app(CurrentTenant::class)->tenant();
        $writable = $tenant->isWritable();
        $draft = $this->status === StockEntryStatus::Draft;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'entry_date' => $this->entry_date->toDateString(),
            'warehouse' => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name],
            'supplier' => $this->supplier ? ['id' => $this->supplier->id, 'name' => $this->supplier->name] : null,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'document' => $this->document_path === null ? null : [
                'name' => $this->document_name,
                'url' => $this->documentUrl(),
            ],
            'total_amount' => $this->totalAmount($tenant->currency),
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(
                fn (StockEntryLine $line): array => $this->line($line, $tenant->currency),
            )->values()),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'validated_by' => $this->whenLoaded('validator', fn () => $this->validator?->name),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $writable && $draft && Gate::allows('update', $this->resource),
                'delete' => $writable && $draft && Gate::allows('delete', $this->resource),
                'validate' => $writable && $draft && Gate::allows('validate', $this->resource),
                'cancel' => $writable
                    && $this->status === StockEntryStatus::Validated
                    && Gate::allows('cancel', $this->resource),
                'document' => $writable
                    && $this->status !== StockEntryStatus::Cancelled
                    && Gate::allows('update', $this->resource),
            ],
        ];
    }

    private function totalAmount(string $currency): ?string
    {
        $total = $this->total_amount
            ?? $this->lines_sum_line_total
            ?? ($this->relationLoaded('lines') ? $this->lines->reduce(
                fn (string $carry, StockEntryLine $line): string => bcadd($carry, (string) $line->line_total, 4),
                '0',
            ) : null);

        return $total === null ? null : Money::normalize((string) $total, $currency);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(StockEntryLine $line, string $currency): array
    {
        $unit = $line->article->unit;

        return [
            'id' => $line->id,
            'position' => $line->position,
            'article' => [
                'id' => $line->article->id,
                'code' => $line->article->code,
                'name' => $line->article->name,
                'track_expiry' => $line->article->track_expiry,
                'unit' => ['symbol' => $unit->symbol, 'allows_decimals' => (bool) $unit->allows_decimals],
            ],
            'packaging_id' => $line->packaging_id,
            'entered_unit' => $line->entered_unit,
            'factor' => Quantity::format((string) $line->factor, true),
            'entered_quantity' => Quantity::format((string) $line->entered_quantity, true),
            'quantity' => Quantity::format((string) $line->quantity, (bool) $unit->allows_decimals),
            'unit_price' => Money::normalize((string) $line->unit_price, $currency),
            'line_total' => Money::normalize((string) $line->line_total, $currency),
            'expiry_date' => $line->expiry_date?->toDateString(),
            'lot_number' => $line->lot_number,
            'sale_price' => $line->sale_price === null ? null : Money::normalize((string) $line->sale_price, $currency),
            'lot' => $line->relationLoaded('lot') && $line->lot
                ? ['id' => $line->lot->id, 'code' => $line->lot->code]
                : null,
        ];
    }

    /**
     * Lien signé vers le bon : stable environ une heure, et différent dès que le fichier change.
     */
    private function documentUrl(): string
    {
        return URL::temporarySignedRoute(
            'stock-entries.document',
            now()->startOfHour()->addHours(2),
            ['entry' => $this->id, 'v' => substr(md5((string) $this->document_path), 0, 8)],
        );
    }
}