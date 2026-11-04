<?php

namespace App\Support\Stock;

use App\Models\Article;
use App\Models\ArticlePackaging;
use App\Support\Money;
use DomainException;

final class LineCalculator
{
    /**
     * Convertit une quantité saisie (dans l'unité de l'article ou dans un conditionnement)
     * en quantité de l'unité de base, qui est celle du stock.
     *
     * @return array{factor: string, quantity: string}
     *
     * @throws DomainException avec un message destiné à l'utilisateur
     */
    public static function convert(Article $article, ?ArticlePackaging $packaging, string $enteredQuantity): array
    {
        $factor = $packaging !== null ? (string) $packaging->quantity : '1';
        $product = bcmul($enteredQuantity, $factor, 6);

        $fraction = rtrim(explode('.', $product)[1] ?? '', '0');

        if (strlen($fraction) > 3) {
            throw new DomainException(
                'Cette quantité convertie a plus de 3 décimales : saisissez une quantité plus simple.',
            );
        }

        $quantity = bcadd($product, '0', 3);

        if (bccomp($quantity, '0', 3) <= 0) {
            throw new DomainException('La quantité doit être supérieure à zéro.');
        }

        if (! $article->unit->allows_decimals && $fraction !== '') {
            $shown = rtrim(rtrim($quantity, '0'), '.');

            throw new DomainException(
                "Cette quantité représente {$shown} {$article->unit->symbol}, mais l'unité « {$article->unit->name} » n'accepte pas les décimales.",
            );
        }

        return ['factor' => bcadd($factor, '0', 3), 'quantity' => $quantity];
    }

    /**
     * Total d'une ligne : quantité saisie × prix saisi, arrondi aux décimales de la devise.
     */
    public static function total(string $enteredQuantity, string $unitPrice, string $currency): string
    {
        return Money::round(bcmul($enteredQuantity, $unitPrice, 8), Money::decimals($currency));
    }
}