<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Article;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Stock\LineCalculator;
use App\Support\Stock\SalePricing;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use DomainException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('create', Sale::class);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        $payments = $this->input('payments');

        $this->merge([
            'sale_date' => $this->clean($this->input('sale_date')),
            'customer_id' => $this->clean($this->input('customer_id')),
            'notes' => $this->clean($this->input('notes')),
            'lines' => is_array($lines)
                ? array_map(fn ($line) => is_array($line) ? array_merge($line, [
                    'packaging_id' => $this->clean($line['packaging_id'] ?? null),
                    'entered_quantity' => $this->clean($line['entered_quantity'] ?? null),
                    'unit_price' => $this->clean($line['unit_price'] ?? null),
                    'discount_type' => $this->clean($line['discount_type'] ?? null),
                    'discount_value' => $this->clean($line['discount_value'] ?? null),
                ]) : $line, $lines)
                : $lines,
            'payments' => is_array($payments)
                ? array_map(fn ($payment) => is_array($payment) ? array_merge($payment, [
                    'amount' => $this->clean($payment['amount'] ?? null),
                    'reference' => $this->clean($payment['reference'] ?? null),
                ]) : $payment, $payments)
                : $payments,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sale_date' => ['nullable', 'date_format:Y-m-d'],
            'warehouse_id' => ['required', 'string', 'size:26'],
            'customer_id' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.article_id' => ['required', 'string', 'size:26'],
            'lines.*.packaging_id' => ['nullable', 'string', 'size:26'],
            'lines.*.entered_quantity' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,3})?$/'],
            'lines.*.unit_price' => ['bail', 'nullable', 'string', $this->moneyRule('Le prix unitaire')],
            'lines.*.discount_type' => ['nullable', 'in:percent,amount'],
            'lines.*.discount_value' => ['nullable', 'string'],
            'payments' => ['nullable', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['bail', 'required', 'string', $this->moneyRule('Le montant')],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sale_date.date_format' => 'La date de la vente est invalide.',
            'warehouse_id.required' => "Choisissez l'entrepôt d'où part la marchandise.",
            'notes.max' => 'Les notes ne peuvent pas dépasser 500 caractères.',
            'lines.required' => 'Ajoutez au moins un article à la vente.',
            'lines.min' => 'Ajoutez au moins un article à la vente.',
            'lines.max' => 'Une vente ne peut pas contenir plus de 100 lignes.',
            'lines.*.article_id.required' => 'Choisissez un article.',
            'lines.*.entered_quantity.required' => 'Indiquez la quantité.',
            'lines.*.entered_quantity.regex' => 'La quantité est invalide : utilisez des chiffres avec au plus 3 décimales.',
            'lines.*.discount_type.in' => 'Le type de remise est invalide.',
            'payments.max' => 'Une vente ne peut pas avoir plus de 10 paiements.',
            'payments.*.method.required' => 'Choisissez le mode de paiement.',
            'payments.*.method.enum' => "Ce mode de paiement n'est pas valide.",
            'payments.*.amount.required' => 'Indiquez le montant du paiement.',
            'payments.*.reference.max' => 'La référence ne peut pas dépasser 100 caractères.',
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
        $currency = $tenant->currency;
        $decimals = Money::decimals($currency);
        $today = now($tenant->timezone)->toDateString();

        if ($this->input('sale_date') !== null && $this->input('sale_date') > $today) {
            $validator->errors()->add('sale_date', 'La date de la vente ne peut pas être dans le futur.');
        }

        $warehouse = Warehouse::query()->find($this->input('warehouse_id'));

        if ($warehouse === null) {
            $validator->errors()->add('warehouse_id', 'Cet entrepôt est introuvable.');
        } elseif (! $warehouse->is_active) {
            $validator->errors()->add('warehouse_id', 'Cet entrepôt est désactivé. Choisissez-en un autre.');
        }

        if ($this->input('customer_id') !== null) {
            $customer = Customer::query()->find($this->input('customer_id'));

            if ($customer === null) {
                $validator->errors()->add('customer_id', 'Ce client est introuvable.');
            } elseif (! $customer->is_active) {
                $validator->errors()->add('customer_id', 'Ce client est désactivé. Choisissez-en un autre.');
            }
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
                $validator->errors()->add("lines.{$index}.article_id", 'Cet article est désactivé : choisissez-en un autre.');
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
                $converted = LineCalculator::convert($article, $packaging, $line['entered_quantity']);
            } catch (DomainException $exception) {
                $validator->errors()->add("lines.{$index}.entered_quantity", $exception->getMessage());

                continue;
            }

            $unitPrice = $line['unit_price'] ?? SalePricing::listPrice($article, $converted['factor'], $currency);
            $gross = LineCalculator::total($line['entered_quantity'], $unitPrice, $currency);

            $this->checkDiscount($validator, $index, $line, $gross, $currency);
        }

        foreach ($this->input('payments') ?? [] as $index => $payment) {
            if (bccomp($payment['amount'], '0', $decimals) <= 0) {
                $validator->errors()->add("payments.{$index}.amount", 'Le montant du paiement doit être supérieur à zéro.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function checkDiscount(Validator $validator, int $index, array $line, string $gross, string $currency): void
    {
        $type = $line['discount_type'] ?? null;
        $value = $line['discount_value'] ?? null;
        $key = "lines.{$index}.discount_value";

        if ($type === null && $value === null) {
            return;
        }

        if ($type === null) {
            $validator->errors()->add("lines.{$index}.discount_type", 'Choisissez le type de remise (pourcentage ou montant).');

            return;
        }

        if ($value === null) {
            $validator->errors()->add($key, 'Indiquez la valeur de la remise.');

            return;
        }

        if ($type === 'percent') {
            if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) || bccomp($value, '0', 2) <= 0 || bccomp($value, '100', 2) > 0) {
                $validator->errors()->add($key, 'Le pourcentage de remise doit être compris entre 0 et 100.');
            }

            return;
        }

        $decimals = Money::decimals($currency);
        $pattern = $decimals > 0 ? '/^\d{1,13}(\.\d{1,'.$decimals.'})?$/' : '/^\d{1,13}$/';

        if (! preg_match($pattern, $value) || bccomp($value, '0', $decimals) <= 0) {
            $validator->errors()->add($key, 'La remise est invalide : saisissez un montant supérieur à zéro, dans les décimales de la devise.');

            return;
        }

        if (bccomp($value, $gross, $decimals) > 0) {
            $validator->errors()->add($key, "La remise ne peut pas dépasser le total de la ligne ({$gross} {$currency}).");
        }
    }

    private function moneyRule(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            $decimals = Money::decimals(app(CurrentTenant::class)->tenant()->currency);
            $pattern = $decimals > 0 ? '/^\d{1,13}(\.\d{1,'.$decimals.'})?$/' : '/^\d{1,13}$/';

            if (! is_string($value) || ! preg_match($pattern, $value)) {
                $fail($decimals > 0
                    ? "{$label} est invalide : saisissez un montant avec au plus {$decimals} décimales."
                    : "{$label} est invalide : saisissez un montant entier, sans décimales.");
            }
        };
    }

    private function clean(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}