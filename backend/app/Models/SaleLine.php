<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleLine extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'in_packaging' => 'boolean',
            'factor' => 'decimal:3',
            'entered_quantity' => 'decimal:3',
            'quantity' => 'decimal:3',
            'list_price' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'gross_total' => 'decimal:4',
            'line_total' => 'decimal:4',
            'cost_total' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SaleAllocation::class)->orderBy('created_at')->orderBy('id');
    }
}