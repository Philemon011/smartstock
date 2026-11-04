<?php

namespace App\Http\Requests;

use App\Enums\StockEntryReason;
use App\Models\Article;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Stock\LineCalculator;
use App\Support\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('stock_entry');

        if ($entry instanceof StockEntry) {
            Gate::authorize('update', $entry);
        } else {
            Gate::authorize('create', StockEntry::class);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['reason', 'entry_date', 'warehouse_id', 'supplier_id', 'reference', 'notes'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->text($this->input($field));
            }
        }

        if ($this->has('lines') && is_array($this->input('lines'))) {
            $normalized['lines'] = array_map(function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                return [
                    'article_id' => $this->text($line['article_id'] ?? null),
                    'packaging_id' => $this->text($line['packaging_id'] ?? null),
                    'entered_quantity' => $this->number($line['entered_quantity'] ?? null),
                    'unit_price' => $this->number($line['unit_price'] ?? null),
                    'expiry_date' => $this->text($line['expiry_date'] ?? null),
                    'lot_number' => $this->text($line['lot_number'] ?? null),
                    'sale_price' => $this->number($line['sale_price'] ?? null),
                ];
            }, $this->input('lines'));
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(StockEntryReason::class)],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'warehouse_id' => ['required', 'string', 'size:26'],
            'supplier_id' => ['nullable', 'string', 'size:26'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.article_id' => ['required', 'string', 'size:26'],
            'lines.*.packaging_id' => ['nullable', 'string', 'size:26'],
            'lines.*.entered_quantity' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,3})?$/'],
            'lines.*.unit_price' => ['required', 'string'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:60'],
            'lines.*.sale_price' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => "Choisissez le motif de l'entrée.",
            'reason.enum' => "Ce motif n'est pas valide.",
            'entry_date.required' => "Indiquez la date de l'entrée.",
            'entry_date.date_format' => "La date de l'entrée n'est pas valide.",
            'warehouse_id.required' => "Choisissez l'entrepôt qui reçoit la marchandise.",
            'warehouse_id.string' => "L'entrepôt n'est pas valide.",
            'warehouse_id.size' => "L'entrepôt n'est pas valide.",
            'supplier_id.string' => "Le fournisseur n'est pas valide.",
            'supplier_id.size' => "Le fournisseur n'est pas valide.",
            'reference.max' => 'Le numéro du bon ne peut pas dépasser 60 caractères.',
            'notes.max' => 'Les notes ne peuvent pas dépasser 500 caractères.',
            'lines.required' => "Ajoutez au moins une ligne à l'entrée.",
            'lines.array' => "Les lignes de l'entrée ne sont pas valides.",
            'lines.min' => "Ajoutez au moins une ligne à l'entrée.",
            'lines.max' => 'Une entrée ne peut pas contenir plus de 100 lignes.',
            'lines.*.article_id.required' => 'Choisissez l\'article de cette ligne.',
            'lines.*.article_id.string' => "L'article n'est pas valide.",
            'lines.*.article_id.size' => "L'article n'est pas valide.",
            'lines.*.packaging_id.string' => "Le conditionnement n'est pas valide.",
            'lines.*.packaging_id.size' => "Le conditionnement n'est pas valide.",
            'lines.*.entered_quantity.required' => 'Indiquez la quantité reçue.',
            'lines.*.entered_quantity.string' => 'La quantité reçue n\'est pas valide.',
            'lines.*.entered_quantity.regex' => 'La quantité doit être un nombre valide, avec 3 décimales au maximum.',
            'lines.*.unit_price.required' => "Indiquez le prix d'achat.",
            'lines.*.expiry_date.date_format' => "La date d'expiration n'est pas valide.",
            'lines.*.lot_number.max' => 'Le numéro de lot ne peut pas dépasser 60 caractères.',
        ];
    }

    /**
     * Contrôles qui croisent plusieurs données (articles, conditionnements, devise, dates).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->crossChecks($validator)];
    }

    private function crossChecks(Validator $validator): void
    {
        $tenant = app(CurrentTenant::class)->tenant();
        $currency = $tenant->currency;
        $errors = $validator->errors();
        $reason = StockEntryReason::tryFrom((string) $this->input('reason'));

        $warehouseId = $this->input('warehouse_id');

        if (is_string($warehouseId) && ! $errors->has('warehouse_id')) {
            $warehouse = Warehouse::query()->find($warehouseId);

            if ($warehouse === null) {
                $errors->add('warehouse_id', 'Cet entrepôt est introuvable.');
            } elseif (! $warehouse->is_active) {
                $errors->add('warehouse_id', 'Cet entrepôt est désactivé. Choisissez-en un autre.');
            }
        }

        $supplierId = $this->input('supplier_id');

        if (is_string($supplierId) && ! $errors->has('supplier_id')) {
            $supplier = Supplier::query()->find($supplierId);

            if ($supplier === null) {
                $errors->add('supplier_id', 'Ce fournisseur est introuvable.');
            } elseif (! $supplier->is_active) {
                $errors->add('supplier_id', 'Ce fournisseur est désactivé. Choisissez-en un autre.');
            }
        } elseif ($supplierId === null && $reason?->requiresSupplier()) {
            $errors->add('supplier_id', "Choisissez le fournisseur d'un achat.");
        }

        $entryDate = $this->input('entry_date');
        $entryDate = is_string($entryDate) && ! $errors->has('entry_date') ? $entryDate : null;

        if ($entryDate !== null && $entryDate > now($tenant->timezone)->toDateString()) {
            $errors->add('entry_date', "La date de l'entrée ne peut pas être dans le futur.");
        }

        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        $articleIds = collect($lines)
            ->map(fn ($line) => is_array($line) ? ($line['article_id'] ?? null) : null)
            ->filter(fn ($id) => is_string($id))
            ->unique()
            ->all();

        $articles = Article::query()->with(['unit', 'packagings'])->whereIn('id', $articleIds)->get()->keyBy('id');

        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $key = fn (string $field): string => "lines.{$index}.{$field}";
            $article = is_string($line['article_id'] ?? null) ? $articles->get($line['article_id']) : null;

            if (is_string($line['article_id'] ?? null) && ! $errors->has($key('article_id'))) {
                if ($article === null) {
                    $errors->add($key('article_id'), 'Cet article est introuvable.');
                } elseif (! $article->is_active) {
                    $errors->add($key('article_id'), 'Cet article est désactivé. Réactivez-le ou retirez cette ligne.');
                }
            }

            if ($article === null || ! $article->is_active) {
                continue;
            }

            $packaging = null;

            if (($line['packaging_id'] ?? null) !== null) {
                $packaging = $article->packagings->firstWhere('id', $line['packaging_id']);

                if ($packaging === null) {
                    $errors->add($key('packaging_id'), "Ce conditionnement n'appartient pas à cet article.");

                    continue;
                }
            }

            $quantity = $line['entered_quantity'] ?? null;

            if (is_string($quantity) && ! $errors->has($key('entered_quantity'))) {
                try {
                    LineCalculator::convert($article, $packaging, $quantity);
                } catch (DomainException $exception) {
                    $errors->add($key('entered_quantity'), $exception->getMessage());
                }
            }

            $price = $line['unit_price'] ?? null;

            if (is_string($price)) {
                $problem = $this->amountProblem($price, "Le prix d'achat", $currency);

                if ($problem !== null) {
                    $errors->add($key('unit_price'), $problem);
                } elseif (bccomp($price, '0', 4) === 0 && ! $reason?->allowsZeroPrice()) {
                    $errors->add($key('unit_price'), "Le prix d'achat doit être supérieur à zéro (seul un don peut être à zéro).");
                }
            }

            $salePrice = $line['sale_price'] ?? null;

            if (is_string($salePrice)) {
                $problem = $this->amountProblem($salePrice, 'Le prix de vente', $currency);

                if ($problem !== null) {
                    $errors->add($key('sale_price'), $problem);
                }
            }

            $expiry = $line['expiry_date'] ?? null;

            if ($article->track_expiry) {
                if ($expiry === null) {
                    $errors->add($key('expiry_date'), "Indiquez la date d'expiration : cet article est périssable.");
                } elseif (is_string($expiry) && ! $errors->has($key('expiry_date')) && $entryDate !== null && $expiry < $entryDate) {
                    $errors->add($key('expiry_date'), "La date d'expiration ne peut pas être antérieure à la date de l'entrée.");
                }
            } elseif ($expiry !== null) {
                $errors->add($key('expiry_date'), "Cet article n'a pas de date d'expiration : laissez ce champ vide.");
            }
        }
    }

    private function amountProblem(string $value, string $label, string $currency): ?string
    {
        if (! preg_match('/^\d{1,12}(\.\d+)?$/', $value)) {
            return "{$label} doit être un montant valide, par exemple 500.";
        }

        $decimals = Money::decimals($currency);
        $fraction = rtrim(explode('.', $value)[1] ?? '', '0');

        if (strlen($fraction) > $decimals) {
            return $decimals === 0
                ? "{$label} ne peut pas avoir de décimales en {$currency}."
                : "{$label} ne peut pas avoir plus de {$decimals} décimale(s) en {$currency}.";
        }

        return null;
    }

    private function text(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = preg_replace('/\s+/u', ' ', trim($value));

        return $clean === '' ? null : $clean;
    }

    private function number(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $number = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        return $number === '' ? null : $number;
    }
}