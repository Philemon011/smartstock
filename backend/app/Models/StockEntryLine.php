<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockEntryLine extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'in_packaging' => 'boolean',
            'factor' => 'decimal:3',
            'entered_quantity' => 'decimal:3',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'expiry_date' => 'date',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function packaging(): BelongsTo
    {
        return $this->belongsTo(ArticlePackaging::class, 'packaging_id');
    }

    public function lot(): HasOne
    {
        return $this->hasOne(StockLot::class, 'stock_entry_line_id');
    }
}