<?php

namespace App\Services;

use App\Enums\StockEntryStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Article;
use App\Models\StockExit;
use App\Models\StockExitAllocation;
use App\Models\StockExitLine;
use App\Models\StockLot;
use App\Support\Stock\LineCalculator;
use App\Support\Quantity;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use DomainException;

class StockExitService
{
    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly StockService $stock,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StockExit
    {
        return DB::transaction(function () use ($data): StockExit {
            $current = app(CurrentTenant::class);

            $exit = (new StockExit)->forceFill([
                'tenant_id' => $current->id(),
                'status' => StockEntryStatus::Draft,
                'created_by' => auth()->id(),
            ] + $this->header($data));
            $exit->save();

            $this->replaceLines($exit, $data['lines']);

            return $this->fresh($exit);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StockExit $exit, array $data): StockExit
    {
        return DB::transaction(function () use ($exit, $data): StockExit {
            $this->lockTenant();
            $exit->refresh();
            $this->assertDraft($exit, 'modifiée');

            $exit->forceFill($this->header($data))->save();
            $exit->lines()->delete();
            $this->replaceLines($exit, $data['lines']);

            return $this->fresh($exit);
        });
    }

    public function delete(StockExit $exit): void
    {
        DB::transaction(function () use ($exit): void {
            $this->lockTenant();
            $exit->refresh();
            $this->assertDraft($exit, 'supprimée');

            $exit->lines()->delete();
            $exit->delete();
        });
    }

    public function validate(StockExit $exit): StockExit
    {
        return DB::transaction(function () use ($exit): StockExit {
            $this->lockTenant();
            $exit->refresh();
            $this->assertDraft($exit, 'validée');
            $exit->load(['warehouse', 'supplier', 'lines.article.unit', 'lines.article.packagings']);

            $tenant = app(CurrentTenant::class)->tenant();
            $today = now($tenant->timezone)->toDateString();

            $this->assertHeaderIsValid($exit, $today);

            $total = '0';

            foreach ($exit->lines as $line) {
                $this->assertLineIsStillValid($line);

                $lineCost = '0';

                foreach ($this->pick($exit, $line, $today) as [$lot, $quantity]) {
                    if ($exit->exit_date->toDateString() < $lot->received_date->toDateString()) {
                        throw new BusinessRuleException(
                            "La date de la sortie est antérieure à la réception du lot {$lot->code} (le {$lot->received_date->format('d/m/Y')}). Choisissez une date plus récente.",
                            'exit_before_reception',
                        );
                    }

                    $allocation = $this->stock->issue($exit, $line, $lot, $quantity, $tenant->currency);
                    $lineCost = bcadd($lineCost, (string) $allocation->value, 4);
                }

                $line->forceFill(['cost_total' => $lineCost])->save();
                $total = bcadd($total, $lineCost, 4);
            }

            $exit->forceFill([
                'number' => sprintf('SOR-%06d', $this->sequences->next($tenant->id, 'stock_exit')),
                'status' => StockEntryStatus::Validated,
                'total_cost' => $total,
                'validated_at' => now(),
                'validated_by' => auth()->id(),
            ])->save();

            return $this->fresh($exit);
        });
    }

    public function cancel(StockExit $exit, string $reason): StockExit
    {
        return DB::transaction(function () use ($exit, $reason): StockExit {
            $this->lockTenant();
            $exit->refresh();

            if ($exit->status !== StockEntryStatus::Validated) {
                throw new BusinessRuleException(
                    'Seule une sortie validée peut être annulée.',
                    'exit_not_validated',
                );
            }

            $allocations = StockExitAllocation::query()
                ->whereIn('stock_exit_line_id', $exit->lines()->pluck('id'))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            foreach ($allocations as $allocation) {
                $this->stock->restore($exit, $allocation);
            }

            $exit->forceFill([
                'status' => StockEntryStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason,
            ])->save();

            return $this->fresh($exit);
        });
    }

    // ------------------------------------------------------------------ Prélèvement

    /**
     * Détermine les lots et quantités à prélever pour une ligne, en verrouillant les lots.
     *
     * @return list<array{0: StockLot, 1: string}>
     */
    private function pick(StockExit $exit, StockExitLine $line, string $today): array
    {
        $article = $line->article;
        $symbol = $article->unit->symbol;
        $decimals = (bool) $article->unit->allows_decimals;
        $needed = (string) $line->quantity;
        $onlyExpired = $exit->reason->onlyExpiredLots();
        $format = fn (string $value): string => Quantity::format($value, $decimals);

        if ($line->lot_id !== null) {
            $lot = StockLot::query()->lockForUpdate()->find($line->lot_id);

            if ($lot === null
                || $lot->article_id !== $article->id
                || $lot->warehouse_id !== $exit->warehouse_id
                || $lot->voided_at !== null
                || bccomp((string) $lot->quantity_remaining, '0', 3) <= 0) {
                throw new BusinessRuleException(
                    "Le lot choisi pour « {$article->name} » n'est plus disponible dans cet entrepôt.",
                    'lot_unavailable',
                );
            }

            if ($onlyExpired && ! $this->isExpired($lot, $today)) {
                throw new BusinessRuleException(
                    "Le lot {$lot->code} n'est pas expiré : une sortie pour péremption ne concerne que des lots expirés.",
                    'lot_not_expired',
                );
            }

            if (bccomp((string) $lot->quantity_remaining, $needed, 3) < 0) {
                throw new BusinessRuleException(
                    "Le lot {$lot->code} ne contient que {$format((string) $lot->quantity_remaining)} {$symbol}, mais {$format($needed)} {$symbol} sont demandés.",
                    'lot_insufficient',
                );
            }

            return [[$lot, $needed]];
        }

        $lots = StockLot::query()
            ->where('article_id', $article->id)
            ->where('warehouse_id', $exit->warehouse_id)
            ->where('quantity_remaining', '>', 0)
            ->whereNull('voided_at')
            ->when(
                $onlyExpired,
                fn ($query) => $query->where('expiry_date', '<', $today),
                fn ($query) => $query->where(
                    fn ($inner) => $inner->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today),
                ),
            )
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('received_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = $lots->reduce(fn (string $sum, StockLot $lot) => bcadd($sum, (string) $lot->quantity_remaining, 3), '0');

        if (bccomp($available, $needed, 3) < 0) {
            $warehouse = $exit->warehouse->name;

            throw new BusinessRuleException(
                $onlyExpired
                    ? "Stock expiré insuffisant pour « {$article->name} » dans l'entrepôt « {$warehouse} » : {$format($available)} {$symbol} expiré(s), {$format($needed)} {$symbol} demandé(s)."
                    : "Stock insuffisant pour « {$article->name} » dans l'entrepôt « {$warehouse} » : {$format($available)} {$symbol} disponible(s) (hors lots expirés), {$format($needed)} {$symbol} demandé(s).",
                $onlyExpired ? 'insufficient_expired_stock' : 'insufficient_stock',
            );
        }

        $picked = [];
        $remaining = $needed;

        foreach ($lots as $lot) {
            if (bccomp($remaining, '0', 3) <= 0) {
                break;
            }

            $take = bccomp((string) $lot->quantity_remaining, $remaining, 3) <= 0
                ? (string) $lot->quantity_remaining
                : $remaining;

            $picked[] = [$lot, $take];
            $remaining = bcsub($remaining, $take, 3);
        }

        return $picked;
    }

    private function isExpired(StockLot $lot, string $today): bool
    {
        return $lot->expiry_date !== null && $lot->expiry_date->toDateString() < $today;
    }

    // ------------------------------------------------------------------ Contrôles

    private function assertDraft(StockExit $exit, string $action): void
    {
        if (! $exit->isDraft()) {
            throw new BusinessRuleException(
                "Cette sortie n'est plus un brouillon : elle ne peut pas être {$action}.",
                'exit_not_draft',
            );
        }
    }

    private function assertHeaderIsValid(StockExit $exit, string $today): void
    {
        if ($exit->lines->isEmpty()) {
            throw new BusinessRuleException('Ajoutez au moins une ligne avant de valider la sortie.', 'exit_empty');
        }

        if (! $exit->warehouse->is_active) {
            throw new BusinessRuleException(
                "L'entrepôt « {$exit->warehouse->name} » est désactivé. Réactivez-le ou modifiez la sortie.",
                'warehouse_inactive',
            );
        }

        if ($exit->supplier !== null && ! $exit->supplier->is_active) {
            throw new BusinessRuleException(
                "Le fournisseur « {$exit->supplier->name} » est désactivé. Réactivez-le ou modifiez la sortie.",
                'supplier_inactive',
            );
        }

        if ($exit->reason->requiresSupplier() && $exit->supplier === null) {
            throw new BusinessRuleException(
                'Choisissez le fournisseur à qui vous retournez la marchandise.',
                'supplier_required',
            );
        }

        if ($exit->exit_date->toDateString() > $today) {
            throw new BusinessRuleException('La date de la sortie ne peut pas être dans le futur.', 'exit_date_future');
        }
    }

        private function assertLineIsStillValid(StockExitLine $line): void
    {
        $article = $line->article;

        if (! $article->is_active) {
            throw new BusinessRuleException(
                "L'article « {$article->name} » est désactivé. Réactivez-le ou retirez la ligne.",
                'article_inactive',
            );
        }

        $packaging = $line->in_packaging ? $article->packagings->firstWhere('id', $line->packaging_id) : null;

        $outdated = new BusinessRuleException(
            "Le conditionnement de « {$article->name} » a changé depuis la saisie. Modifiez la sortie avant de la valider.",
            'exit_outdated',
        );

        if ($line->in_packaging && $packaging === null) {
            throw $outdated;
        }

        try {
            $converted = LineCalculator::convert($article, $packaging, (string) $line->entered_quantity);
        } catch (DomainException) {
            throw $outdated;
        }

        if (bccomp($converted['quantity'], (string) $line->quantity, 3) !== 0) {
            throw $outdated;
        }
    }

    // ------------------------------------------------------------------ Outils

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        return [
            'reason' => $data['reason'],
            'exit_date' => $data['exit_date'],
            'warehouse_id' => $data['warehouse_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

        /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(StockExit $exit, array $lines): void
    {
        $articles = Article::query()
            ->with(['unit', 'packagings'])
            ->whereIn('id', array_column($lines, 'article_id'))
            ->get()
            ->keyBy('id');

        foreach (array_values($lines) as $index => $data) {
            $article = $articles[$data['article_id']];
            $packaging = ! empty($data['packaging_id'])
                ? $article->packagings->firstWhere('id', $data['packaging_id'])
                : null;

            $converted = LineCalculator::convert($article, $packaging, (string) $data['entered_quantity']);

            (new StockExitLine)->forceFill([
                'tenant_id' => $exit->tenant_id,
                'stock_exit_id' => $exit->id,
                'position' => $index + 1,
                'article_id' => $article->id,
                'packaging_id' => $packaging?->id,
                'in_packaging' => $packaging !== null,
                'entered_unit' => $packaging?->name ?? $article->unit->symbol,
                'factor' => $converted['factor'],
                'entered_quantity' => $data['entered_quantity'],
                'quantity' => $converted['quantity'],
                'lot_id' => $data['lot_id'] ?? null,
            ])->save();
        }
    }
    

    /**
     * Toutes les opérations de stock d'une entreprise passent l'une après l'autre.
     */
    private function lockTenant(): void
    {
        DB::table('tenants')->where('id', app(CurrentTenant::class)->id())->lockForUpdate()->first();
    }

    private function fresh(StockExit $exit): StockExit
    {
        return $exit->refresh()->load([
            'warehouse', 'supplier', 'creator:id,name', 'validator:id,name', 'canceller:id,name',
            'lines.article.unit', 'lines.lot', 'lines.allocations.lot',
        ]);
    }
}