<?php

namespace App\Http\Resources;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin \App\Models\Warehouse
 */
class WarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $writable = app(CurrentTenant::class)->tenant()->isWritable();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'city' => $this->city,
            'phone' => $this->phone,
            'is_main' => $this->is_main,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $writable && Gate::allows('update', $this->resource),
                'delete' => $writable && Gate::allows('delete', $this->resource),
                'toggle' => $writable && Gate::allows('deactivate', $this->resource),
                'set_main' => $writable && Gate::allows('setMain', $this->resource),
            ],
        ];
    }
}