<?php

namespace App\Services;

use App\Models\StockEntry;
use App\Models\StockEntryLine;
use App\Models\StockLot;
use App\Models\StockMovement;
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
}