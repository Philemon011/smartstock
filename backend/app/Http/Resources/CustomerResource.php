<?php

namespace App\Http\Resources;

use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin \App\Models\Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = app(CurrentTenant::class);
        $writable = $current->tenant()->isWritable();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'notes' => $this->notes,
            'credit_limit' => $this->credit_limit !== null
                ? Money::normalize((string) $this->credit_limit, $current->tenant()->currency)
                : null,
                            'balance_due' => $this->getAttribute('balance_due'),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $writable && Gate::allows('update', $this->resource),
                'delete' => $writable && Gate::allows('delete', $this->resource),
                'toggle' => $writable && Gate::allows('deactivate', $this->resource),
                'set_credit_limit' => $writable && $current->role()->canManageStructure(),
            ],
        ];
    }
}