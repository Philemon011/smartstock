<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Article;

class UnitService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Unit
    {
        return $this->atomically(function () use ($data): Unit {
            $unit = new Unit($data);
            $unit->save();

            return $unit->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Unit $unit, array $data): Unit
    {
        return $this->atomically(function () use ($unit, $data): Unit {
            $unit->refresh();

            $decimalsChange = array_key_exists('allows_decimals', $data)
                && (bool) $data['allows_decimals'] !== $unit->allows_decimals;

            if ($decimalsChange && $this->isUsed($unit)) {
                throw new BusinessRuleException(
                    'Cette unité est utilisée par des articles : sa règle sur les décimales ne peut plus changer.',
                    'unit_used',
                );
            }

            $unit->fill($data)->save();

            return $unit->refresh();
        });
    }

    public function deactivate(Unit $unit): Unit
    {
        return $this->atomically(function () use ($unit): Unit {
            $unit->refresh();
            $unit->forceFill(['is_active' => false])->save();

            return $unit;
        });
    }

    public function activate(Unit $unit): Unit
    {
        return $this->atomically(function () use ($unit): Unit {
            $unit->refresh();
            $unit->forceFill(['is_active' => true])->save();

            return $unit;
        });
    }

    public function delete(Unit $unit): void
    {
        $this->atomically(function () use ($unit): void {
            $unit->refresh();

            if ($this->isUsed($unit)) {
                throw new BusinessRuleException(
                    'Des articles utilisent cette unité. Désactivez-la plutôt que de la supprimer.',
                    'unit_used',
                );
            }

            $unit->delete();
        });
    }

    /**
     * Une unité est « utilisée » si des articles s'en servent.
     * Le module Articles complétera cette règle quand ces données existeront.
     */
        private function isUsed(Unit $unit): bool
    {
        return Article::query()->where('unit_id', $unit->id)->exists();
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
                // Deux personnes ont saisi le même nom ou symbole au même instant.
                $onSymbol = str_contains($exception->getMessage(), 'units_tenant_symbol_unique');

                throw ValidationException::withMessages(
                    $onSymbol
                        ? ['symbol' => 'Ce symbole est déjà utilisé par une autre unité.']
                        : ['name' => 'Une unité portant ce nom existe déjà. Choisissez un autre nom.'],
                );
            }
        });
    }
}