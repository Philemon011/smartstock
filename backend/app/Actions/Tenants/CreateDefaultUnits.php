<?php

namespace App\Actions\Tenants;

use App\Models\Tenant;
use App\Models\Unit;

class CreateDefaultUnits
{
    /**
     * Nom, symbole, accepte les décimales.
     *
     * @var list<array{0: string, 1: string, 2: bool}>
     */
    public const DEFAULTS = [
        ['Pièce', 'pce', false],
        ['Kilogramme', 'kg', true],
        ['Gramme', 'g', true],
        ['Litre', 'L', true],
        ['Millilitre', 'mL', true],
        ['Mètre', 'm', true],
        ['Sac', 'sac', false],
        ['Bidon', 'bidon', false],
        ['Carton', 'carton', false],
        ['Paquet', 'paquet', false],
        ['Boîte', 'boîte', false],
        ['Bouteille', 'bouteille', false],
        ['Sachet', 'sachet', false],
        ['Palette', 'palette', false],
    ];

    /**
     * @return int Nombre d'unités ajoutées
     */
    public function handle(Tenant $tenant): int
    {
        $existing = Unit::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get(['name_key', 'symbol_key']);
        $names = $existing->pluck('name_key')->all();
        $symbols = $existing->pluck('symbol_key')->all();

        $created = 0;

        foreach (self::DEFAULTS as [$name, $symbol, $allowsDecimals]) {
            if (in_array(Unit::keyFor($name), $names, true) || in_array(Unit::symbolKey($symbol), $symbols, true)) {
                continue;
            }

            $unit = (new Unit)->forceFill([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'symbol' => $symbol,
                'allows_decimals' => $allowsDecimals,
            ]);
            $unit->save();

            $created++;
        }

        return $created;
    }
}