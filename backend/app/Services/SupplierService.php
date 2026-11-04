<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\StockEntry;

class SupplierService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Supplier
    {
        return $this->atomically(function () use ($data): Supplier {
            $supplier = new Supplier($data);
            $supplier->save();

            return $supplier->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        return $this->atomically(function () use ($supplier, $data): Supplier {
            $supplier->fill($data)->save();

            return $supplier->refresh();
        });
    }

    public function deactivate(Supplier $supplier): Supplier
    {
        return $this->atomically(function () use ($supplier): Supplier {
            $supplier->refresh();
            $supplier->forceFill(['is_active' => false])->save();

            return $supplier;
        });
    }

    public function activate(Supplier $supplier): Supplier
    {
        return $this->atomically(function () use ($supplier): Supplier {
            $supplier->refresh();
            $supplier->forceFill(['is_active' => true])->save();

            return $supplier;
        });
    }

    public function delete(Supplier $supplier): void
    {
        $this->atomically(function () use ($supplier): void {
            $supplier->refresh();

            if ($this->isUsed($supplier)) {
                throw new BusinessRuleException(
                    'Ce fournisseur a déjà livré de la marchandise. Désactivez-le plutôt que de le supprimer.',
                    'supplier_used',
                );
            }

            $supplier->delete();
        });
    }

    /**
     * Un fournisseur est « utilisé » s'il apparaît dans des entrées de stock.
     * Le module Entrées complétera cette règle quand ces données existeront.
     */
        private function isUsed(Supplier $supplier): bool
    {
        return StockEntry::query()->where('supplier_id', $supplier->id)->exists();
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
            } catch (UniqueConstraintViolationException) {
                // Deux personnes ont saisi le même nom au même instant.
                throw ValidationException::withMessages([
                    'name' => 'Un fournisseur portant ce nom existe déjà. Choisissez un autre nom.',
                ]);
            }
        });
    }
}