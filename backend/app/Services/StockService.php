<?php

namespace App\Services;

use App\Models\StockEntry;
use App\Models\StockEntryLine;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockExit;
use App\Models\StockExitLine;
use App\Models\StockExitAllocation;
use App\Exceptions\BusinessRuleException;
use App\Support\Money;
use App\Models\Sale;
use App\Models\SaleAllocation;
use App\Models\SaleLine;

use Illuminate\Support\Facades\Auth;

class StockService
{
    public function __construct(private readonly SequenceGenerator $sequences)
    {
    }

    /**
     * Crée le lot d'une ligne d'entrée validée et l'écrit dans le journal.
     */
    public function receive(StockEntry $entry, StockEntryLine $line): StockLot
    {
        $unitCost = bcdiv((string) $line->line_total, (string) $line->quantity, 6);

        $lot = (new StockLot)->forceFill([
            'tenant_id' => $entry->tenant_id,
            'code' => sprintf('LOT-%06d', $this->sequences->next($entry->tenant_id, 'lot')),
            'article_id' => $line->article_id,
            'warehouse_id' => $entry->warehouse_id,
            'stock_entry_line_id' => $line->id,
            'lot_number' => $line->lot_number,
            'received_date' => $entry->entry_date,
            'expiry_date' => $line->expiry_date,
            'quantity_initial' => $line->quantity,
            'quantity_remaining' => $line->quantity,
            'total_cost' => $line->line_total,
            'unit_cost' => $unitCost,
        ]);
        $lot->save();

        $this->record('entry', $lot, (string) $line->quantity, $unitCost, $entry);

        return $lot;
    }

    /**
     * Annule un lot resté intact : il disparaît du stock, l'historique reste.
     */
    public function void(StockEntry $entry, StockLot $lot): void
    {
        $quantity = (string) $lot->quantity_initial;

        $lot->forceFill(['quantity_remaining' => '0', 'voided_at' => now()])->save();

        $this->record('entry_cancellation', $lot, bcmul($quantity, '-1', 3), (string) $lot->unit_cost, $entry);
    }

    private function record(string $type, StockLot $lot, string $quantity, string $unitCost, StockEntry $entry): void
    {
        (new StockMovement)->forceFill([
            'tenant_id' => $lot->tenant_id,
            'type' => $type,
            'article_id' => $lot->article_id,
            'warehouse_id' => $lot->warehouse_id,
            'lot_id' => $lot->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'stock_entry_id' => $entry->id,
            'created_by' => Auth::id(),
        ])->save();
    }


        /**
     * Prélève une quantité d'un lot pour une sortie validée et garde la trace exacte du prélèvement.
     */
    public function issue(StockExit $exit, StockExitLine $line, StockLot $lot, string $quantity, string $currency): StockExitAllocation
    {
        $lot->forceFill([
            'quantity_remaining' => bcsub((string) $lot->quantity_remaining, $quantity, 3),
        ])->save();

        $value = Money::round(bcmul($quantity, (string) $lot->unit_cost, 9), Money::decimals($currency));

        $allocation = (new StockExitAllocation)->forceFill([
            'tenant_id' => $exit->tenant_id,
            'stock_exit_line_id' => $line->id,
            'lot_id' => $lot->id,
            'quantity' => $quantity,
            'unit_cost' => $lot->unit_cost,
            'value' => $value,
        ]);
        $allocation->save();

        $this->recordExit('exit', $exit, $lot, bcmul($quantity, '-1', 3), (string) $lot->unit_cost);

        return $allocation;
    }

    /**
     * Remet dans son lot d'origine la quantité d'un prélèvement (annulation d'une sortie).
     */
    public function restore(StockExit $exit, StockExitAllocation $allocation): void
    {
        $lot = StockLot::query()->lockForUpdate()->findOrFail($allocation->lot_id);

        if ($lot->voided_at !== null) {
            throw new BusinessRuleException(
                "Le lot {$lot->code} a été annulé : cette sortie ne peut plus être annulée.",
                'lot_voided',
            );
        }

        $lot->forceFill([
            'quantity_remaining' => bcadd((string) $lot->quantity_remaining, (string) $allocation->quantity, 3),
        ])->save();

        $this->recordExit('exit_cancellation', $exit, $lot, (string) $allocation->quantity, (string) $allocation->unit_cost);
    }

    private function recordExit(string $type, StockExit $exit, StockLot $lot, string $quantity, string $unitCost): void
    {
        $movement = (new StockMovement)->forceFill([
            'tenant_id' => $exit->tenant_id,
            'type' => $type,
            'article_id' => $lot->article_id,
            'warehouse_id' => $lot->warehouse_id,
            'lot_id' => $lot->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'stock_exit_id' => $exit->id,
            'created_by' => auth()->id(),
        ]);
        $movement->save();
    }

        /**
     * Prélève une quantité d'un lot pour une vente et garde la trace exacte du prélèvement.
     */
    public function sell(Sale $sale, SaleLine $line, StockLot $lot, string $quantity, string $cost): SaleAllocation
    {
        $locked = StockLot::query()->lockForUpdate()->findOrFail($lot->id);

        $locked->forceFill([
            'quantity_remaining' => bcsub((string) $locked->quantity_remaining, $quantity, 3),
        ])->save();

        $allocation = (new SaleAllocation)->forceFill([
            'tenant_id' => $sale->tenant_id,
            'sale_line_id' => $line->id,
            'lot_id' => $locked->id,
            'quantity' => $quantity,
            'unit_cost' => $locked->unit_cost,
            'cost' => $cost,
        ]);
        $allocation->save();

        $this->recordSale('sale', $sale, $locked, bcmul($quantity, '-1', 3), (string) $locked->unit_cost);

        return $allocation;
    }

    private function recordSale(string $type, Sale $sale, StockLot $lot, string $quantity, string $unitCost): void
    {
        $movement = (new StockMovement)->forceFill([
            'tenant_id' => $sale->tenant_id,
            'type' => $type,
            'article_id' => $lot->article_id,
            'warehouse_id' => $lot->warehouse_id,
            'lot_id' => $lot->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'sale_id' => $sale->id,
            'created_by' => auth()->id(),
        ]);
        $movement->save();
    }



        /**
     * Remet dans son lot d'origine la quantité d'un prélèvement (annulation d'une vente).
     */
    public function restoreSale(Sale $sale, SaleAllocation $allocation): void
    {
        $lot = StockLot::query()->lockForUpdate()->findOrFail($allocation->lot_id);

        if ($lot->voided_at !== null) {
            throw new BusinessRuleException(
                "Le lot {$lot->code} a été annulé : cette vente ne peut plus être annulée.",
                'lot_voided',
            );
        }

        $lot->forceFill([
            'quantity_remaining' => bcadd((string) $lot->quantity_remaining, (string) $allocation->quantity, 3),
        ])->save();

        $this->recordSale('sale_cancellation', $sale, $lot, (string) $allocation->quantity, (string) $allocation->unit_cost);
    }
}