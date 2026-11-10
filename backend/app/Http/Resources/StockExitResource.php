<?php

namespace App\Http\Resources;

use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StockExit */
class StockExitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = app(CurrentTenant::class);
        $currency = $current->tenant()->currency;
        $role = $current->role();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'exit_date' => $this->exit_date->toDateString(),
            'warehouse' => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name],
            'supplier' => $this->supplier ? ['id' => $this->supplier->id, 'name' => $this->supplier->name] : null,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'total_cost' => $this->total_cost !== null ? Money::normalize((string) $this->total_cost, $currency) : null,
            'lines_count' => $this->whenCounted('lines'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'validated_by' => $this->whenLoaded('validator', fn () => $this->validator?->name),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
            'cancel_reason' => $this->cancel_reason,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(function ($line) use ($currency) {
                $decimals = (bool) $line->article->unit->allows_decimals;

                return [
                    'id' => $line->id,
                    'article' => [
                        'id' => $line->article->id,
                        'code' => $line->article->code,
                        'name' => $line->article->name,
                        'unit' => ['symbol' => $line->article->unit->symbol, 'allows_decimals' => $decimals],
                    ],
                    'packaging_id' => $line->packaging_id,
                    'entered_unit' => $line->entered_unit,
                    'factor' => Quantity::format((string) $line->factor, true),
                    'entered_quantity' => Quantity::format((string) $line->entered_quantity, true),
                    'quantity' => Quantity::format((string) $line->quantity, $decimals),
                    'lot' => $line->lot ? ['id' => $line->lot->id, 'code' => $line->lot->code] : null,
                    'cost_total' => $line->cost_total !== null ? Money::normalize((string) $line->cost_total, $currency) : null,
                    'allocations' => $line->allocations->map(fn ($allocation) => [
                        'id' => $allocation->id,
                        'lot' => [
                            'id' => $allocation->lot->id,
                            'code' => $allocation->lot->code,
                            'lot_number' => $allocation->lot->lot_number,
                            'expiry_date' => $allocation->lot->expiry_date?->toDateString(),
                        ],
                        'quantity' => Quantity::format((string) $allocation->quantity, $decimals),
                        'unit_cost' => Money::normalize((string) $allocation->unit_cost, $currency, 6),
                        'value' => Money::normalize((string) $allocation->value, $currency),
                    ])->all(),
                ];
            })->all()),
            'can' => [
                'update' => $this->isDraft() && $role->canManageStock(),
                'delete' => $this->isDraft() && $role->canManageStock(),
                'validate' => $this->isDraft() && $role->canManageStock(),
                'cancel' => $this->status->value === 'validated' && $role->canManageStructure(),
            ],
        ];
    }
}