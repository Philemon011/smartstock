<?php

namespace App\Http\Resources;

use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StockTransfer
 */
class StockTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = app(CurrentTenant::class);
        $currency = $current->tenant()->currency;
        $writable = $current->tenant()->isWritable();
        $role = $current->role();
        $canCosts = $role->canViewCosts();
        $money = fn (mixed $value): string => Money::normalize((string) $value, $currency);
        $plain = fn (mixed $value): string => rtrim(rtrim((string) $value, '0'), '.') ?: '0';

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'transfer_date' => $this->transfer_date->toDateString(),
            'from_warehouse' => ['id' => $this->fromWarehouse->id, 'name' => $this->fromWarehouse->name],
            'to_warehouse' => ['id' => $this->toWarehouse->id, 'name' => $this->toWarehouse->name],
            'reference' => $this->reference,
            'notes' => $this->notes,
            'total_cost' => $this->when($canCosts, fn () => $this->total_cost !== null ? $money($this->total_cost) : null),
            'lines_count' => $this->whenCounted('lines'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'validated_by' => $this->whenLoaded('validator', fn () => $this->validator?->name),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
            'cancel_reason' => $this->cancel_reason,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(function ($line) use ($money, $plain, $canCosts) {
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
                    'cost_total' => $this->when($canCosts, fn () => $line->cost_total !== null ? $money($line->cost_total) : null),
                    'allocations' => $line->allocations->map(fn ($allocation) => [
                        'id' => $allocation->id,
                        'source_lot' => [
                            'id' => $allocation->sourceLot->id,
                            'code' => $allocation->sourceLot->code,
                            'lot_number' => $allocation->sourceLot->lot_number,
                            'expiry_date' => $allocation->sourceLot->expiry_date?->toDateString(),
                        ],
                        'destination_lot' => [
                            'id' => $allocation->destinationLot->id,
                            'code' => $allocation->destinationLot->code,
                        ],
                        'quantity' => Quantity::format((string) $allocation->quantity, $decimals),
                        'unit_cost' => $this->when($canCosts, fn () => $plain($allocation->unit_cost)),
                        'cost' => $this->when($canCosts, fn () => $money($allocation->cost)),
                    ])->all(),
                ];
            })->all()),
            'can' => [
                'update' => $writable && $this->isDraft() && $role->canTransfer(),
                'delete' => $writable && $this->isDraft() && $role->canTransfer(),
                'validate' => $writable && $this->isDraft() && $role->canTransfer(),
                'cancel' => $writable && $this->status->value === 'validated' && $role->canManageStructure(),
            ],
        ];
    }
}