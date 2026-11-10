<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockEntryReason;
use App\Enums\StockEntryStatus;
use App\Enums\StockExitReason;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class StockMetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json([
                'exit_reasons' => array_map(fn (StockExitReason $reason) => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                    'requires_supplier' => $reason->requiresSupplier(),
                    'requires_notes' => $reason->requiresNotes(),
                    'expired_only' => $reason->onlyExpiredLots(),
                ], StockExitReason::cases()),
                'entry_reasons' => collect(StockEntryReason::cases())->map(fn (StockEntryReason $reason): array => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                    'requires_supplier' => $reason->requiresSupplier(),
                    'allows_zero_price' => $reason->allowsZeroPrice(),
                ])->values(),
                'entry_statuses' => collect(StockEntryStatus::cases())->map(fn (StockEntryStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])->values(),
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
