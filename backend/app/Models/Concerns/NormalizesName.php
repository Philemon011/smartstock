<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Pour les modèles qui ont une colonne `name` et une colonne `name_key`.
 */
trait NormalizesName
{
    protected static function bootNormalizesName(): void
    {
        static::saving(function (Model $model): void {
            $model->setAttribute('name_key', static::keyFor((string) $model->getAttribute('name')));
        });
    }

    /**
     * Clé de comparaison : sans accents, sans majuscules, sans espaces en trop.
     */
    public static function keyFor(string $name): string
    {
        return Str::of($name)->squish()->ascii()->lower()->toString();
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => preg_replace('/\s+/u', ' ', trim($value)),
        );
    }
}