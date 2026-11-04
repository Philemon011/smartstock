<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NormalizesName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    use BelongsToTenant, HasUlids, NormalizesName;

    /**
     * Le code, l'activité et la photo ne changent que par les services métier.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'barcode',
        'category_id',
        'unit_id',
        'sale_price',
        'min_stock',
        'track_expiry',
        'expiry_alert_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:4',
            'min_stock' => 'decimal:3',
            'track_expiry' => 'boolean',
            'expiry_alert_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected function barcode(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $barcode = $value === null ? '' : mb_strtoupper(trim($value));

                return $barcode === '' ? null : $barcode;
            },
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $description = $value === null ? '' : trim($value);

                return $description === '' ? null : $description;
            },
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function packagings(): HasMany
    {
        return $this->hasMany(ArticlePackaging::class)->orderBy('quantity')->orderBy('name_key');
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(ArticlePriceChange::class);
    }
}