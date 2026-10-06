<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Expired = 'expired';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Essai',
            self::Active => 'Actif',
            self::Expired => 'Expiré',
            self::Suspended => 'Suspendu',
        };
    }
}