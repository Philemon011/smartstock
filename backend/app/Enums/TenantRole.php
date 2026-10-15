<?php

namespace App\Enums;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case StockManager = 'stock_manager';
    case Seller = 'seller';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propriétaire',
            self::Admin => 'Administrateur',
            self::StockManager => 'Gestionnaire de stock',
            self::Seller => 'Vendeur',
            self::Viewer => 'Lecture seule',
        };
    }

        /**
     * Peut gérer la structure de l'entreprise (entrepôts, catégories, paramètres).
     */
    public function canManageStructure(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

        /**
     * Peut gérer le catalogue (catégories, articles).
     */
    public function canManageCatalog(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::StockManager], true);
    }
}