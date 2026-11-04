<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLot extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'expiry_date' => 'date',
            'quantity_initial' => 'decimal:3',
            'quantity_remaining' => 'decimal:3',
            'total_cost' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'voided_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}