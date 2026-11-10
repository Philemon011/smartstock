<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockExitLine extends Model
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
            'cost_total' => 'decimal:4',
        ];
    }

    public function stockExit(): BelongsTo
    {
        return $this->belongsTo(StockExit::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function packaging(): BelongsTo
    {
        return $this->belongsTo(ArticlePackaging::class, 'packaging_id');
    }

    /** Le lot demandé explicitement (null : prélèvement automatique). */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StockExitAllocation::class)->orderBy('created_at')->orderBy('id');
    }
}