<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SequenceGenerator
{
    /**
     * Numéro suivant d'une séquence de l'entreprise. L'insertion avec mise à jour est atomique :
     * deux appels simultanés ne reçoivent jamais le même numéro.
     */
    public function next(string $tenantId, string $name): int
    {
        $row = DB::selectOne(
            'INSERT INTO sequences (tenant_id, name, last_value) VALUES (?, ?, 1)
             ON CONFLICT (tenant_id, name) DO UPDATE SET last_value = sequences.last_value + 1
             RETURNING last_value',
            [$tenantId, $name],
        );

        return (int) $row->last_value;
    }
}