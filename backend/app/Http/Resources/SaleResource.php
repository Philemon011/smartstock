<?php

namespace App\Http\Resources;

use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin \App\Models\Sale
 */
class SaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = app(CurrentTenant::class);
        $currency = $current->tenant()->currency;
        $writable = $current->tenant()->isWritable();
        $canCosts = $current->role()->canViewCosts();
        $money = fn (mixed $value): string => Money::normalize((string) $value, $currency);

        $validated = $this->status->value === 'validated';
        $balance = $validated ? $money(bcsub((string) $this->total, (string) $this->amount_paid, 4)) : $money('0');

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sale_date' => $this->sale_date->toDateString(),
            'warehouse' => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name],
            'customer' => $this->customer
                ? ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone]
                : null,
            'notes' => $this->notes,
            'subtotal' => $money($this->subtotal),
            'discount_total' => $money($this->discount_total),
            'total' => $money($this->total),
            'amount_paid' => $money($this->amount_paid),
            'balance_due' => $balance,
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'cost_total' => $this->when($canCosts, fn () => $money($this->cost_total)),
            'margin' => $this->when($canCosts, fn () => $money(bcsub((string) $this->total, (string) $this->cost_total, 4))),
            'lines_count' => $this->whenCounted('lines'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
            'cancel_reason' => $this->cancel_reason,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(function ($line) use ($money, $canCosts) {
                $decimals = (bool) $line->article->unit->allows_decimals;
                $percent = $line->discount_type === 'percent';

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
                    'entered_quantity' => Quantity::format((string) $line->entered_quantity, true),
                    'quantity' => Quantity::format((string) $line->quantity, $decimals),
                    'list_price' => $money($line->list_price),
                    'unit_price' => $money($line->unit_price),
                    'discount' => $line->discount_type === null ? null : [
                        'type' => $line->discount_type,
                        'value' => $percent
                            ? rtrim(rtrim((string) $line->discount_value, '0'), '.')
                            : $money($line->discount_value),
                        'amount' => $money($line->discount_amount),
                    ],
                    'gross_total' => $money($line->gross_total),
                    'line_total' => $money($line->line_total),
                    'cost_total' => $this->when($canCosts, fn () => $money($line->cost_total)),
                    'allocations' => $line->allocations->map(fn ($allocation) => [
                        'id' => $allocation->id,
                        'lot' => [
                            'id' => $allocation->lot->id,
                            'code' => $allocation->lot->code,
                            'lot_number' => $allocation->lot->lot_number,
                            'expiry_date' => $allocation->lot->expiry_date?->toDateString(),
                        ],
                        'quantity' => Quantity::format((string) $allocation->quantity, $decimals),
                        'unit_cost' => $this->when($canCosts, fn () => rtrim(rtrim((string) $allocation->unit_cost, '0'), '.') ?: '0'),
                    ])->all(),
                ];
            })->all()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'method' => $payment->method->value,
                'method_label' => $payment->method->label(),
                'amount' => $money($payment->amount),
                'tendered' => $payment->tendered !== null ? $money($payment->tendered) : null,
                'change_given' => $money($payment->change_given),
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at->toIso8601String(),
                                'status' => $payment->status,
                'voided_at' => $payment->voided_at?->toIso8601String(),
                'void_reason' => $payment->void_reason,
                'created_by' => $payment->creator?->name,
            ])->all()),
            'can' => [
                                'void_payment' => $writable && $validated && Gate::allows('voidPayment', $this->resource),
                'cancel' => $writable && $validated && Gate::allows('cancel', $this->resource),
                'collect' => $writable && $validated
                    && bccomp((string) $this->total, (string) $this->amount_paid, 4) > 0
                    && Gate::allows('collect', $this->resource),
            ],
        ];
    }
}