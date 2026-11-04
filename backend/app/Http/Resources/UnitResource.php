<?php

namespace App\Http\Resources;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin \App\Models\Unit
 */
class UnitResource extends JsonResource
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
            'symbol' => $this->symbol,
            'allows_decimals' => $this->allows_decimals,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $writable && Gate::allows('update', $this->resource),
                'delete' => $writable && Gate::allows('delete', $this->resource),
                'toggle' => $writable && Gate::allows('deactivate', $this->resource),
            ],
        ];
    }
}