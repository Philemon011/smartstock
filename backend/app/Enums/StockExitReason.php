<?php

namespace App\Enums;

enum StockExitReason: string
{
    case Loss = 'loss';
    case Breakage = 'breakage';
    case Expiry = 'expiry';
    case InternalUse = 'internal_use';
    case Donation = 'donation';
    case SupplierReturn = 'supplier_return';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Loss => 'Perte',
            self::Breakage => 'Casse',
            self::Expiry => 'Péremption',
            self::InternalUse => 'Usage interne',
            self::Donation => 'Don',
            self::SupplierReturn => 'Retour fournisseur',
            self::Other => 'Autre',
        };
    }

    public function requiresSupplier(): bool
    {
        return $this === self::SupplierReturn;
    }

    public function requiresNotes(): bool
    {
        return $this === self::Other;
    }

    /** Une sortie pour péremption ne concerne que des lots expirés. */
    public function onlyExpiredLots(): bool
    {
        return $this === self::Expiry;
    }
}