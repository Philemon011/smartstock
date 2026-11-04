<?php

namespace App\Services;

use App\Enums\StockEntryStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Article;
use App\Models\StockEntry;
use App\Models\StockEntryLine;
use App\Models\StockLot;
use App\Models\Tenant;
use App\Support\Stock\LineCalculator;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StockEntryService
{
    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly StockService $stock,
        private readonly ArticleService $articles,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StockEntry
    {
        return $this->atomically(function () use ($data): StockEntry {
            $lines = $data['lines'];
            unset($data['lines']);

            $entry = new StockEntry($data);
            $entry->forceFill(['created_by' => Auth::id()])->save();

            $this->replaceLines($entry, $lines);

            return $entry->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StockEntry $entry, array $data): StockEntry
    {
        return $this->atomically(function () use ($entry, $data): StockEntry {
            $entry->refresh();
            $this->assertDraft($entry, 'modifiée');

            $lines = $data['lines'];
            unset($data['lines']);

            $entry->fill($data)->save();
            $this->replaceLines($entry, $lines);

            return $entry->refresh();
        });
    }

    public function delete(StockEntry $entry): void
    {
        $documentPath = $entry->document_path;

        $this->atomically(function () use ($entry): void {
            $entry->refresh();
            $this->assertDraft($entry, 'supprimée');

            $entry->delete();
        });

        if ($documentPath !== null) {
            Storage::disk(config('filesystems.files_disk'))->delete($documentPath);
        }
    }

    /**
     * Valide un brouillon : crée un lot par ligne et met le stock à jour. Tout est refait et revérifié ici,
     * car les articles, conditionnements ou fournisseurs ont pu changer depuis la saisie.
     */
    public function validate(StockEntry $entry): StockEntry
    {
        return $this->atomically(function () use ($entry): StockEntry {
            $entry->refresh();
            $this->assertDraft($entry, 'validée');

            $entry->load(['warehouse', 'supplier', 'lines.article.unit', 'lines.article.packagings']);

            $this->assertHeaderIsValid($entry);

            $currency = app(CurrentTenant::class)->tenant()->currency;
            $total = '0';

            foreach ($entry->lines->sortBy('position') as $line) {
                $this->assertLineIsStillValid($entry, $line, $currency);

                $this->stock->receive($entry, $line);
                $total = bcadd($total, (string) $line->line_total, 4);

                if ($line->sale_price !== null) {
                    $this->articles->changeSalePrice($line->article, (string) $line->sale_price);
                }
            }

            $entry->forceFill([
                'number' => sprintf('ENT-%06d', $this->sequences->next($entry->tenant_id, 'stock_entry')),
                'status' => StockEntryStatus::Validated,
                'total_amount' => $total,
                'validated_at' => now(),
                'validated_by' => Auth::id(),
            ])->save();

            return $entry->refresh();
        });
    }

    /**
     * Annule une entrée validée dont la marchandise n'a pas bougé : les lots sortent du stock, l'historique reste.
     */
    public function cancel(StockEntry $entry, string $reason): StockEntry
    {
        return $this->atomically(function () use ($entry, $reason): StockEntry {
            $entry->refresh();

            if ($entry->status !== StockEntryStatus::Validated) {
                throw new BusinessRuleException(
                    'Seule une entrée validée peut être annulée.',
                    'entry_not_validated',
                );
            }

            $lots = StockLot::query()
                ->whereIn('stock_entry_line_id', $entry->lines()->pluck('id'))
                ->lockForUpdate()
                ->get();

            $moved = $lots->contains(
                fn (StockLot $lot): bool => bccomp((string) $lot->quantity_remaining, (string) $lot->quantity_initial, 3) !== 0,
            );

            if ($moved) {
                throw new BusinessRuleException(
                    'Cette entrée ne peut plus être annulée : une partie de la marchandise a déjà été vendue ou sortie du stock. Corrigez le stock par un ajustement.',
                    'entry_lots_used',
                );
            }

            foreach ($lots as $lot) {
                $this->stock->void($entry, $lot);
            }

            $entry->forceFill([
                'status' => StockEntryStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancel_reason' => $reason,
            ])->save();

            return $entry->refresh();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(StockEntry $entry, array $lines): void
    {
        $currency = app(CurrentTenant::class)->tenant()->currency;

        $entry->lines()->delete();

        $articles = Article::query()
            ->with(['unit', 'packagings'])
            ->whereIn('id', collect($lines)->pluck('article_id')->all())
            ->get()
            ->keyBy('id');

        foreach (array_values($lines) as $position => $input) {
            /** @var Article $article */
            $article = $articles->get($input['article_id']);
            $packagingId = $input['packaging_id'] ?? null;
            $packaging = $packagingId !== null ? $article->packagings->firstWhere('id', $packagingId) : null;

            try {
                $converted = LineCalculator::convert($article, $packaging, $input['entered_quantity']);
            } catch (DomainException $exception) {
                throw new BusinessRuleException($exception->getMessage(), 'invalid_line');
            }

            (new StockEntryLine)->forceFill([
                'tenant_id' => $entry->tenant_id,
                'stock_entry_id' => $entry->id,
                'position' => $position,
                'article_id' => $article->id,
                'packaging_id' => $packaging?->id,
                'in_packaging' => $packaging !== null,
                'entered_unit' => $packaging?->name ?? $article->unit->symbol,
                'factor' => $converted['factor'],
                'entered_quantity' => $input['entered_quantity'],
                'quantity' => $converted['quantity'],
                'unit_price' => $input['unit_price'],
                'line_total' => LineCalculator::total($input['entered_quantity'], $input['unit_price'], $currency),
                'expiry_date' => $input['expiry_date'] ?? null,
                'lot_number' => $input['lot_number'] ?? null,
                'sale_price' => $input['sale_price'] ?? null,
            ])->save();
        }
    }

    private function assertDraft(StockEntry $entry, string $action): void
    {
        if (! $entry->isDraft()) {
            throw new BusinessRuleException(
                "Cette entrée est déjà validée ou annulée : elle ne peut pas être {$action}. Annulez-la si elle contient une erreur.",
                'entry_not_draft',
            );
        }
    }

    private function assertHeaderIsValid(StockEntry $entry): void
    {
        $tenant = app(CurrentTenant::class)->tenant();

        if ($entry->lines->isEmpty()) {
            throw new BusinessRuleException('Ajoutez au moins une ligne avant de valider.', 'entry_empty');
        }

        if (! $entry->warehouse->is_active) {
            throw new BusinessRuleException(
                "L'entrepôt « {$entry->warehouse->name} » est désactivé. Choisissez-en un autre.",
                'warehouse_inactive',
            );
        }

        if ($entry->supplier !== null && ! $entry->supplier->is_active) {
            throw new BusinessRuleException(
                "Le fournisseur « {$entry->supplier->name} » est désactivé. Choisissez-en un autre.",
                'supplier_inactive',
            );
        }

        if ($entry->reason->requiresSupplier() && $entry->supplier === null) {
            throw new BusinessRuleException("Choisissez le fournisseur d'un achat.", 'supplier_required');
        }

        if ($entry->entry_date->toDateString() > now($tenant->timezone)->toDateString()) {
            throw new BusinessRuleException(
                "La date de l'entrée ne peut pas être dans le futur.",
                'entry_date_future',
            );
        }
    }

    private function assertLineIsStillValid(StockEntry $entry, StockEntryLine $line, string $currency): void
    {
        $article = $line->article;

        if (! $article->is_active) {
            throw new BusinessRuleException(
                "L'article « {$article->name} » est désactivé. Réactivez-le ou retirez la ligne.",
                'article_inactive',
            );
        }

        $packaging = null;

        if ($line->in_packaging) {
            $packaging = $article->packagings->firstWhere('id', $line->packaging_id);

            if ($packaging === null) {
                throw new BusinessRuleException(
                    "Le conditionnement « {$line->entered_unit} » de l'article « {$article->name} » n'existe plus. Corrigez la ligne.",
                    'packaging_missing',
                );
            }
        }

        try {
            $converted = LineCalculator::convert($article, $packaging, (string) $line->entered_quantity);
        } catch (DomainException $exception) {
            throw new BusinessRuleException("{$article->name} : {$exception->getMessage()}", 'invalid_line');
        }

        $total = LineCalculator::total((string) $line->entered_quantity, (string) $line->unit_price, $currency);

        if (
            bccomp($converted['quantity'], (string) $line->quantity, 3) !== 0
            || bccomp($converted['factor'], (string) $line->factor, 3) !== 0
            || bccomp($total, (string) $line->line_total, 4) !== 0
        ) {
            throw new BusinessRuleException(
                "L'article « {$article->name} » a changé depuis la saisie (unité ou conditionnement). Ouvrez le brouillon et enregistrez-le de nouveau.",
                'entry_outdated',
            );
        }

        if ($article->track_expiry && $line->expiry_date === null) {
            throw new BusinessRuleException(
                "Indiquez la date d'expiration de « {$article->name} » : cet article est périssable.",
                'expiry_required',
            );
        }

        if ($line->expiry_date !== null && $line->expiry_date->toDateString() < $entry->entry_date->toDateString()) {
            throw new BusinessRuleException(
                "La date d'expiration de « {$article->name} » est antérieure à la date de l'entrée.",
                'expiry_before_entry',
            );
        }
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

            return $callback();
        });
    }
}