<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case MtnMomo = 'mtn_momo';
    case MoovMoney = 'moov_money';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::MtnMomo => 'MTN MoMo',
            self::MoovMoney => 'Moov Money',
            self::Card => 'Carte bancaire',
            self::BankTransfer => 'Virement',
        };
    }

    /** Seules les espèces donnent lieu à un rendu de monnaie. */
    public function isCash(): bool
    {
        return $this === self::Cash;
    }
}