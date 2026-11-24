<?php

namespace App\Http\Requests;

use App\Models\Article;
use App\Models\StockLot;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Support\Stock\LineCalculator;
use App\Support\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transfer = $this->route('stock_transfer');

        if ($transfer instanceof StockTransfer) {
            Gate::authorize('update', $transfer);
        } else {
            Gate::authorize('create', StockTransfer::class);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        $this->merge([
            'reference' => $this->blankToNull($this->input('reference')),
            'notes' => $this->blankToNull($this->input('notes')),
            'lines' => is_array($lines)
                ? array_map(fn ($line) => is_array($line) ? array_merge($line, [
                    'packaging_id' => $this->blankToNull($line['packaging_id'] ?? null),
                    'lot_id' => $this->blankToNull($line['lot_id'] ?? null),
                    'entered_quantity' => isset($line['entered_quantity']) ? trim((string) $line['entered_quantity']) : null,
                ]) : $line, $lines)
                : $lines,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            'from_warehouse_id' => ['required', 'string', 'size:26'],
            'to_warehouse_id' => ['required', 'string', 'size:26'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.article_id' => ['required', 'string', 'size:26'],
            'lines.*.packaging_id' => ['nullable', 'string', 'size:26'],
            'lines.*.lot_id' => ['nullable', 'string', 'size:26'],
            'lines.*.entered_quantity' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,3})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'transfer_date.required' => 'Indiquez la date du transfert.',
            'transfer_date.date_format' => 'La date du transfert est invalide.',
            'from_warehouse_id.required' => "Choisissez l'entrepôt d'origine.",
            'to_warehouse_id.required' => "Choisissez l'entrepôt de destination.",
            'reference.max' => 'La référence ne peut pas dépasser 60 caractères.',
            'notes.max' => 'Les notes ne peuvent pas dépasser 500 caractères.',
            'lines.required' => 'Ajoutez au moins une ligne au transfert.',
            'lines.min' => 'Ajoutez au moins une ligne au transfert.',
            'lines.max' => 'Un transfert ne peut pas contenir plus de 100 lignes.',
            'lines.*.article_id.required' => 'Choisissez un article.',
            'lines.*.entered_quantity.required' => 'Indiquez la quantité.',
            'lines.*.entered_quantity.regex' => 'La quantité est invalide : utilisez des chiffres avec au plus 3 décimales.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->crossChecks($validator)];
    }

    private function crossChecks(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $tenant = app(CurrentTenant::class)->tenant();
        $today = now($tenant->timezone)->toDateString();

        if ($this->input('transfer_date') > $today) {
            $validator->errors()->add('transfer_date', 'La date du transfert ne peut pas être dans le futur.');
        }

        foreach (['from_warehouse_id', 'to_warehouse_id'] as $field) {
            $warehouse = Warehouse::query()->find($this->input($field));

            if ($warehouse === null) {
                $validator->errors()->add($field, 'Cet entrepôt est introuvable.');
            } elseif (! $warehouse->is_active) {
                $validator->errors()->add($field, 'Cet entrepôt est désactivé. Choisissez-en un autre.');
            }
        }

        if ($this->input('from_warehouse_id') === $this->input('to_warehouse_id')) {
            $validator->errors()->add(
                'to_warehouse_id',
                "Choisissez deux entrepôts différents : la destination doit être autre que l'origine.",
            );
        }

        $lines = $this->input('lines');
        $articles = Article::query()
            ->with(['unit', 'packagings'])
            ->whereIn('id', array_column($lines, 'article_id'))
            ->get()
            ->keyBy('id');

        foreach ($lines as $index => $line) {
            $article = $articles->get($line['article_id']);

            if ($article === null) {
                $validator->errors()->add("lines.{$index}.article_id", 'Cet article est introuvable.');

                continue;
            }

            if (! $article->is_active) {
                $validator->errors()->add("lines.{$index}.article_id", 'Cet article est désactivé : réactivez-le ou choisissez-en un autre.');
            }

            $packaging = null;

            if ($line['packaging_id'] !== null) {
                $packaging = $article->packagings->firstWhere('id', $line['packaging_id']);

                if ($packaging === null) {
                    $validator->errors()->add("lines.{$index}.packaging_id", "Ce conditionnement n'appartient pas à cet article.");

                    continue;
                }
            }

            try {
                LineCalculator::convert($article, $packaging, $line['entered_quantity']);
            } catch (DomainException $exception) {
                $validator->errors()->add("lines.{$index}.entered_quantity", $exception->getMessage());
            }

            if ($line['lot_id'] !== null) {
                $this->checkLot($validator, $index, $line['lot_id'], $article, (string) $this->input('from_warehouse_id'), $today);
            }
        }
    }

    private function checkLot(Validator $validator, int $index, string $lotId, Article $article, string $warehouseId, string $today): void
    {
        $key = "lines.{$index}.lot_id";
        $lot = StockLot::query()->find($lotId);

        if ($lot === null || $lot->voided_at !== null) {
            $validator->errors()->add($key, 'Ce lot est introuvable.');
        } elseif ($lot->article_id !== $article->id) {
            $validator->errors()->add($key, "Ce lot n'appartient pas à cet article.");
        } elseif ($lot->warehouse_id !== $warehouseId) {
            $validator->errors()->add($key, "Ce lot se trouve dans un autre entrepôt que l'entrepôt d'origine.");
        } elseif (bccomp((string) $lot->quantity_remaining, '0', 3) <= 0) {
            $validator->errors()->add($key, 'Ce lot est épuisé.');
        } elseif ($lot->expiry_date !== null && $lot->expiry_date->toDateString() < $today) {
            $validator->errors()->add($key, 'Ce lot est expiré : un lot expiré ne se transfère pas, sortez-le pour péremption.');
        }
    }

    private function blankToNull(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}