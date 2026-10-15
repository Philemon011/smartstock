<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin \App\Models\Category
 */
class CategoryResource extends JsonResource
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
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'is_active' => $this->is_active,
            'depth' => $this->isRoot() ? 0 : 1,
            'children_count' => $this->whenCounted('children'),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $writable && Gate::allows('update', $this->resource),
                'delete' => $writable && Gate::allows('delete', $this->resource),
                'toggle' => $writable && Gate::allows('deactivate', $this->resource),
                'add_child' => $writable
                    && Gate::allows('create', Category::class)
                    && $this->isRoot()
                    && $this->is_active,
            ],
        ];
    }
}