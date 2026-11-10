<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        return $this->atomically(function () use ($data): Customer {
            $customer = new Customer($data);
            $customer->save();

            return $customer->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        return $this->atomically(function () use ($customer, $data): Customer {
            $customer->fill($data)->save();

            return $customer->refresh();
        });
    }

    public function deactivate(Customer $customer): Customer
    {
        return $this->atomically(function () use ($customer): Customer {
            $customer->refresh();
            $customer->forceFill(['is_active' => false])->save();

            return $customer;
        });
    }

    public function activate(Customer $customer): Customer
    {
        return $this->atomically(function () use ($customer): Customer {
            $customer->refresh();
            $customer->forceFill(['is_active' => true])->save();

            return $customer;
        });
    }

    public function delete(Customer $customer): void
    {
        $this->atomically(function () use ($customer): void {
            $customer->refresh();
            $customer->delete();
        });
    }

    /**
     * Toutes les opérations d'une entreprise passent l'une après l'autre.
     * Le module Ventes ajoutera ici le refus de supprimer un client déjà facturé.
     *
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

            return $callback();
        });
    }
}