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


        /**
     * Arrondi « demi vers le haut » à un nombre de décimales donné, sans aucun calcul flottant.
     * Ajouter une demi-unité puis tronquer donne l'arrondi commercial pour les montants positifs.
     */
    public static function round(string $amount, int $decimals): string
    {
        $half = '0.'.str_repeat('0', $decimals).'5';

        return bcadd($amount, $half, $decimals);
    }
}