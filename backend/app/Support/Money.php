<?php

namespace App\Support;

final class Money
{
    public static function decimals(string $currency): int
    {
        return (int) config("tenants.currency_decimals.{$currency}", 2);
    }

    /**
     * Montant affiché avec le bon nombre de décimales pour la devise, sans arrondi flottant.
     */
    public static function normalize(string $amount, string $currency): string
    {
        return bcadd($amount, '0', self::decimals($currency));
    }
}