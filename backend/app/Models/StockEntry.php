<?php

namespace App\Models;

use App\Enums\StockEntryReason;
use App\Enums\StockEntryStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockEntry extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * Le statut, le numéro et le total ne changent que par le service métier.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reason',
        'entry_date',
        'warehouse_id',
        'supplier_id',
        'reference',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockEntryStatus::class,
            'reason' => StockEntryReason::class,
            'entry_date' => 'date',
            'total_amount' => 'decimal:4',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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
        return $this->hasMany(StockEntryLine::class);
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

    public function isDraft(): bool
    {
        return $this->status === StockEntryStatus::Draft;
    }
}