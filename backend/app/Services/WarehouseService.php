<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\StockEntry;
use App\Models\StockExit;
use App\Models\StockLot;
use App\Models\Sale;
use App\Models\StockTransfer;

class WarehouseService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Warehouse
    {
        return $this->atomically(function () use ($data): Warehouse {
            $warehouse = new Warehouse($data);
            // Le tout premier entrepôt de l'entreprise devient automatiquement le principal.
            $warehouse->is_main = ! Warehouse::query()->exists();
            $warehouse->save();

            return $warehouse->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return $this->atomically(function () use ($warehouse, $data): Warehouse {
            $warehouse->fill($data)->save();

            return $warehouse->refresh();
        });
    }

    public function setMain(Warehouse $warehouse): Warehouse
    {
        return $this->atomically(function () use ($warehouse): Warehouse {
            $warehouse->refresh();

            if (! $warehouse->is_active) {
                throw new BusinessRuleException(
                    'Activez cet entrepôt avant de le définir comme principal.',
                    'warehouse_inactive',
                );
            }

            if (! $warehouse->is_main) {
                Warehouse::query()->where('is_main', true)->update(['is_main' => false]);
                $warehouse->forceFill(['is_main' => true])->save();
            }

            return $warehouse;
        });
    }

        public function deactivate(Warehouse $warehouse): Warehouse
    {
        return $this->atomically(function () use ($warehouse): Warehouse {
            $warehouse->refresh();

            if ($warehouse->is_main) {
                throw new BusinessRuleException(
                    'Définissez un autre entrepôt principal avant de désactiver celui-ci.',
                    'main_warehouse',
                );
            }

            $holdsStock = StockLot::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('quantity_remaining', '>', 0)
                ->exists();

            if ($holdsStock) {
                throw new BusinessRuleException(
                    'Cet entrepôt contient encore du stock. Videz-le (sorties ou transferts) avant de le désactiver.',
                    'warehouse_has_stock',
                );
            }

            $warehouse->forceFill(['is_active' => false])->save();

            return $warehouse;
        });
    }

    public function activate(Warehouse $warehouse): Warehouse
    {
        return $this->atomically(function () use ($warehouse): Warehouse {
            $warehouse->refresh();
            $warehouse->forceFill(['is_active' => true])->save();

            return $warehouse;
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        $this->atomically(function () use ($warehouse): void {
            $warehouse->refresh();

            if ($warehouse->is_main) {
                throw new BusinessRuleException(
                    'Définissez un autre entrepôt principal avant de supprimer celui-ci.',
                    'main_warehouse',
                );
            }

            if ($this->isUsed($warehouse)) {
                throw new BusinessRuleException(
                    'Cet entrepôt a déjà servi. Désactivez-le plutôt que de le supprimer.',
                    'warehouse_used',
                );
            }

            $warehouse->delete();
        });
    }

    /**
     * Un entrepôt « a servi » s'il porte du stock ou des mouvements.
     * Le module Mouvements de stock complètera cette règle quand ces données existeront.
     */
        private function isUsed(Warehouse $warehouse): bool
    {
                return StockEntry::query()->where('warehouse_id', $warehouse->id)->exists()
            || StockExit::query()->where('warehouse_id', $warehouse->id)->exists()
            || Sale::query()->where('warehouse_id', $warehouse->id)->exists()
                        || StockTransfer::query()->where('from_warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id)->exists();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function atomically(Closure $callback): mixed
    {
        $tenantId = app(CurrentTenant::class)->id();

        return DB::transaction(function () use ($callback, $tenantId) {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            try {
                return $callback();
            } catch (UniqueConstraintViolationException $exception) {
                // Deux personnes ont saisi le même nom ou code au même instant.
                $onCode = str_contains($exception->getMessage(), 'warehouses_tenant_code_unique');

                throw ValidationException::withMessages(
                    $onCode
                        ? ['code' => 'Ce code est déjà utilisé par un autre entrepôt.']
                        : ['name' => 'Un entrepôt portant ce nom existe déjà. Choisissez un autre nom.'],
                );
            }
        });
    }
}