<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferAllocation extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:6',
            'cost' => 'decimal:4',
        ];
    }

    public function sourceLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'source_lot_id');
    }

    public function destinationLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'destination_lot_id');
    }
}