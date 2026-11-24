<?php

namespace App\Services;

use App\Enums\StockEntryStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Article;
use App\Models\StockLot;
use App\Models\StockTransfer;
use App\Models\StockTransferAllocation;
use App\Models\StockTransferLine;
use App\Support\Quantity;
use App\Support\Stock\LineCalculator;
use App\Support\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly StockService $stock,
        private readonly LotPicker $picker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StockTransfer
    {
        return DB::transaction(function () use ($data): StockTransfer {
            $transfer = (new StockTransfer)->forceFill([
                'tenant_id' => app(CurrentTenant::class)->id(),
                'status' => StockEntryStatus::Draft,
                'created_by' => auth()->id(),
            ] + $this->header($data));
            $transfer->save();

            $this->replaceLines($transfer, $data['lines']);

            return $this->fresh($transfer);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StockTransfer $transfer, array $data): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data): StockTransfer {
            $this->lockTenant();
            $transfer->refresh();
            $this->assertDraft($transfer, 'modifié');

            $transfer->forceFill($this->header($data))->save();
            $transfer->lines()->delete();
            $this->replaceLines($transfer, $data['lines']);

            return $this->fresh($transfer);
        });
    }

    public function delete(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $this->lockTenant();
            $transfer->refresh();
            $this->assertDraft($transfer, 'supprimé');

            $transfer->lines()->delete();
            $transfer->delete();
        });
    }

    public function validate(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer): StockTransfer {
            $this->lockTenant();
            $transfer->refresh();
            $this->assertDraft($transfer, 'validé');
            $transfer->load(['fromWarehouse', 'toWarehouse', 'lines.article.unit', 'lines.article.packagings']);

            $tenant = app(CurrentTenant::class)->tenant();
            $today = now($tenant->timezone)->toDateString();

            $this->assertHeaderIsValid($transfer, $today);

            $total = '0';

            foreach ($transfer->lines as $line) {
                $this->assertLineIsStillValid($line);

                $lineCost = '0';

                foreach ($this->pick($transfer, $line, $today) as [$lot, $quantity]) {
                    if ($transfer->transfer_date->toDateString() < $lot->received_date->toDateString()) {
                        throw new BusinessRuleException(
                            "La date du transfert est antérieure à la réception du lot {$lot->code} (le {$lot->received_date->format('d/m/Y')}). Choisissez une date plus récente.",
                            'transfer_before_reception',
                        );
                    }

                    $allocation = $this->stock->transfer($transfer, $line, $lot, $quantity, $tenant->currency);
                    $lineCost = bcadd($lineCost, (string) $allocation->cost, 4);
                }

                $line->forceFill(['cost_total' => $lineCost])->save();
                $total = bcadd($total, $lineCost, 4);
            }

            $transfer->forceFill([
                'number' => sprintf('TRF-%06d', $this->sequences->next($tenant->id, 'stock_transfer')),
                'status' => StockEntryStatus::Validated,
                'total_cost' => $total,
                'validated_at' => now(),
                'validated_by' => auth()->id(),
            ])->save();

            return $this->fresh($transfer);
        });
    }

    public function cancel(StockTransfer $transfer, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $reason): StockTransfer {
            $this->lockTenant();
            $transfer->refresh();

            if ($transfer->status !== StockEntryStatus::Validated) {
                throw new BusinessRuleException('Seul un transfert validé peut être annulé.', 'transfer_not_validated');
            }

            $transfer->load('toWarehouse');

            $allocations = StockTransferAllocation::query()
                ->whereIn('stock_transfer_line_id', $transfer->lines()->pluck('id'))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            // On vérifie tout avant de toucher à quoi que ce soit : le stock arrivé doit être intact.
            foreach ($allocations as $allocation) {
                $destination = StockLot::query()->lockForUpdate()->findOrFail($allocation->destination_lot_id);

                if ($destination->voided_at !== null || bccomp((string) $destination->quantity_remaining, (string) $destination->quantity_initial, 3) !== 0) {
                    throw new BusinessRuleException(
                        "Le stock arrivé dans « {$transfer->toWarehouse->name} » a déjà été utilisé (vendu, sorti ou transféré) : ce transfert ne peut plus être annulé. Lot concerné : {$destination->code}.",
                        'transfer_lots_used',
                    );
                }
            }

            foreach ($allocations as $allocation) {
                $this->stock->reverseTransfer($transfer, $allocation);
            }

            $transfer->forceFill([
                'status' => StockEntryStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason,
            ])->save();

            return $this->fresh($transfer);
        });
    }

    // ------------------------------------------------------------ Prélèvement

    /**
     * @return list<array{0: StockLot, 1: string}>
     */
    private function pick(StockTransfer $transfer, StockTransferLine $line, string $today): array
    {
        $article = $line->article;
        $symbol = $article->unit->symbol;
        $decimals = (bool) $article->unit->allows_decimals;
        $needed = (string) $line->quantity;

        if ($line->lot_id === null) {
            $reserved = [];

            return $this->picker->pick($article, $transfer->fromWarehouse, $needed, $today, $reserved);
        }

        $lot = StockLot::query()->lockForUpdate()->find($line->lot_id);

        if ($lot === null
            || $lot->article_id !== $article->id
            || $lot->warehouse_id !== $transfer->from_warehouse_id
            || $lot->voided_at !== null
            || bccomp((string) $lot->quantity_remaining, '0', 3) <= 0) {
            throw new BusinessRuleException(
                "Le lot choisi pour « {$article->name} » n'est plus disponible dans l'entrepôt d'origine.",
                'lot_unavailable',
            );
        }

        if ($lot->expiry_date !== null && $lot->expiry_date->toDateString() < $today) {
            throw new BusinessRuleException(
                "Le lot {$lot->code} est expiré : un lot expiré ne se transfère pas, sortez-le pour péremption.",
                'lot_expired',
            );
        }

        if (bccomp((string) $lot->quantity_remaining, $needed, 3) < 0) {
            $remaining = Quantity::format((string) $lot->quantity_remaining, $decimals);
            $asked = Quantity::format($needed, $decimals);

            throw new BusinessRuleException(
                "Le lot {$lot->code} ne contient que {$remaining} {$symbol}, mais {$asked} {$symbol} sont demandés.",
                'lot_insufficient',
            );
        }

        return [[$lot, $needed]];
    }

    // --------------------------------------------------------------- Contrôles

    private function assertDraft(StockTransfer $transfer, string $action): void
    {
        if (! $transfer->isDraft()) {
            throw new BusinessRuleException(
                "Ce transfert n'est plus un brouillon : il ne peut pas être {$action}.",
                'transfer_not_draft',
            );
        }
    }

    private function assertHeaderIsValid(StockTransfer $transfer, string $today): void
    {
        if ($transfer->lines->isEmpty()) {
            throw new BusinessRuleException('Ajoutez au moins une ligne avant de valider le transfert.', 'transfer_empty');
        }

        if ($transfer->from_warehouse_id === $transfer->to_warehouse_id) {
            throw new BusinessRuleException(
                "Choisissez deux entrepôts différents : la destination doit être autre que l'origine.",
                'same_warehouse',
            );
        }

        foreach ([$transfer->fromWarehouse, $transfer->toWarehouse] as $warehouse) {
            if (! $warehouse->is_active) {
                throw new BusinessRuleException(
                    "L'entrepôt « {$warehouse->name} » est désactivé. Réactivez-le ou modifiez le transfert.",
                    'warehouse_inactive',
                );
            }
        }

        if ($transfer->transfer_date->toDateString() > $today) {
            throw new BusinessRuleException('La date du transfert ne peut pas être dans le futur.', 'transfer_date_future');
        }
    }

    private function assertLineIsStillValid(StockTransferLine $line): void
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
            "Le conditionnement de « {$article->name} » a changé depuis la saisie. Modifiez le transfert avant de le valider.",
            'transfer_outdated',
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
            'transfer_date' => $data['transfer_date'],
            'from_warehouse_id' => $data['from_warehouse_id'],
            'to_warehouse_id' => $data['to_warehouse_id'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(StockTransfer $transfer, array $lines): void
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

            (new StockTransferLine)->forceFill([
                'tenant_id' => $transfer->tenant_id,
                'stock_transfer_id' => $transfer->id,
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

    private function fresh(StockTransfer $transfer): StockTransfer
    {
        return $transfer->refresh()->load([
            'fromWarehouse', 'toWarehouse', 'creator:id,name', 'validator:id,name', 'canceller:id,name',
            'lines.article.unit', 'lines.lot', 'lines.allocations.sourceLot', 'lines.allocations.destinationLot',
        ]);
    }
}