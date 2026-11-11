<?php

namespace App\Support\Stock;

use App\Models\Article;
use App\Support\Money;

final class SalePricing
{
    /**
     * Prix de la liste pour l'unité saisie : prix de vente de l'unité de base × quantité du conditionnement.
     */
    public static function listPrice(Article $article, string $factor, string $currency): string
    {
        return Money::round(bcmul((string) $article->sale_price, $factor, 8), Money::decimals($currency));
    }

    /**
     * Montant de la remise d'une ligne, arrondi dans la devise (demi vers le haut).
     */
    public static function discount(string $gross, ?string $type, ?string $value, string $currency): string
    {
        if ($type === null || $value === null) {
            return Money::normalize('0', $currency);
        }

        if ($type === 'percent') {
            return Money::round(bcdiv(bcmul($gross, $value, 8), '100', 8), Money::decimals($currency));
        }

        return Money::normalize($value, $currency);
    }
}