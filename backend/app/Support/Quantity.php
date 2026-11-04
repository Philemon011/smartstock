<?php

namespace App\Support;

final class Quantity
{
    /**
     * Quantité lisible : « 25 » et « 2.5 » (jamais « 25.000 »), entière si l'unité n'accepte pas les décimales.
     */
    public static function format(string $value, bool $allowsDecimals): string
    {
        if (! $allowsDecimals) {
            return bcadd($value, '0', 0);
        }

        $formatted = bcadd($value, '0', 3);

        return rtrim(rtrim($formatted, '0'), '.');
    }
}