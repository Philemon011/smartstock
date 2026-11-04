<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NormalizesName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToTenant, HasUlids, NormalizesName;

    /**
     * L'activité ne change que par le service métier.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'symbol',
        'allows_decimals',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allows_decimals' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Unit $unit): void {
            $unit->symbol_key = self::symbolKey((string) $unit->symbol);
        });
    }

    /**
     * Clé de comparaison du symbole : sans majuscules ni espaces autour.
     */
    public static function symbolKey(string $symbol): string
    {
        return mb_strtolower(trim($symbol));
    }

    protected function symbol(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }
}