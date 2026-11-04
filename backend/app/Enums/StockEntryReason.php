<?php

namespace App\Enums;

enum StockEntryReason: string
{
    case Purchase = 'purchase';
    case CustomerReturn = 'customer_return';
    case Donation = 'donation';
    case OpeningStock = 'opening_stock';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Achat fournisseur',
            self::CustomerReturn => 'Retour client',
            self::Donation => 'Don ou échantillon',
            self::OpeningStock => 'Stock initial',
            self::Other => 'Autre',
        };
    }

    public function requiresSupplier(): bool
    {
        return $this === self::Purchase;
    }

    public function allowsZeroPrice(): bool
    {
        return $this === self::Donation;
    }
}