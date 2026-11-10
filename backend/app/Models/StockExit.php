<?php

namespace App\Models;

use App\Enums\StockEntryStatus;
use App\Enums\StockExitReason;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockExit extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => StockEntryStatus::class,
            'reason' => StockExitReason::class,
            'exit_date' => 'date',
            'total_cost' => 'decimal:4',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === StockEntryStatus::Draft;
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockExitLine::class)->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}