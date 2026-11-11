<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payée',
            self::Partial => 'Partiellement payée',
            self::Unpaid => 'Impayée',
        };
    }
}