<?php

namespace App\Console\Commands;

use App\Actions\Tenants\CreateDefaultUnits;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SeedDefaultUnits extends Command
{
    protected $signature = 'units:seed-defaults {--tenant= : Identifiant d\'une seule entreprise (toutes par défaut)}';

    protected $description = 'Ajoute les unités de mesure par défaut aux entreprises qui ne les ont pas encore.';

    public function handle(CreateDefaultUnits $action): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, string $id) => $query->whereKey($id))
            ->get();

        foreach ($tenants as $tenant) {
            $created = $action->handle($tenant);

            $this->line("{$tenant->name} : {$created} unité(s) ajoutée(s).");
        }

        return self::SUCCESS;
    }
}