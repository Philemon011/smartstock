<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockEntryStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Article;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Stock\LineCalculator;
use App\Support\Stock\SalePricing;
use App\Support\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use App\Models\SaleAllocation;

class SaleService
{
        public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly StockService $stock,
        private readonly LotPicker $picker,
        private readonly SalePaymentService $payments,
    ) {
    }

    /**
     * Enregistre et valide une vente : prélève le stock, enregistre les paiements, contrôle le crédit.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Sale
    {
        return DB::transaction(function () use ($data): Sale {
            $tenant = app(CurrentTenant::class)->tenant();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $currency = $tenant->currency;
            $decimals = Money::decimals($currency);
            $today = now($tenant->timezone)->toDateString();
            $saleDate = $data['sale_date'] ?? $today;

            if ($saleDate > $today) {
                throw new BusinessRuleException('La date de la vente ne peut pas être dans le futur.', 'sale_date_future');
            }

            $warehouse = Warehouse::query()->find($data['warehouse_id']);

            if ($warehouse === null || ! $warehouse->is_active) {
                throw new BusinessRuleException("Cet entrepôt n'est plus disponible. Choisissez-en un autre.", 'warehouse_inactive');
            }

            $customer = null;

            if (! empty($data['customer_id'])) {
                $customer = Customer::query()->find($data['customer_id']);

                if ($customer === null || ! $customer->is_active) {
                    throw new BusinessRuleException("Ce client n'est plus disponible. Choisissez-en un autre.", 'customer_inactive');
                }
            }

            $plan = $this->planLines($data['lines'], $warehouse, $saleDate, $today, $currency);

            if (bccomp($plan['total'], '0', $decimals) <= 0) {
                throw new BusinessRuleException('Le total de la vente doit être supérieur à zéro.', 'sale_total_zero');
            }

            $payments = $this->planPayments($data['payments'] ?? [], $plan['total'], $currency);
            $balance = bcsub($plan['total'], $payments['paid'], $decimals);

            if (bccomp($balance, '0', $decimals) > 0) {
                $this->assertCreditAllowed($customer, $balance, $currency);
            }

            $status = bccomp($balance, '0', $decimals) === 0
                ? PaymentStatus::Paid
                : (bccomp($payments['paid'], '0', $decimals) > 0 ? PaymentStatus::Partial : PaymentStatus::Unpaid);

            $sale = (new Sale)->forceFill([
                'tenant_id' => $tenant->id,
                'number' => sprintf('VTE-%06d', $this->sequences->next($tenant->id, 'sale')),
                'status' => StockEntryStatus::Validated,
                'sale_date' => $saleDate,
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer?->id,
                'notes' => $data['notes'] ?? null,
                'subtotal' => $plan['subtotal'],
                'discount_total' => $plan['discount_total'],
                'total' => $plan['total'],
                'amount_paid' => $payments['paid'],
                'cost_total' => $plan['cost_total'],
                'payment_status' => $status,
                'created_by' => auth()->id(),
            ]);
            $sale->save();

            foreach ($plan['lines'] as $index => $item) {
                $line = (new SaleLine)->forceFill([
                    'tenant_id' => $tenant->id,
                    'sale_id' => $sale->id,
                    'position' => $index + 1,
                    'article_id' => $item['article']->id,
                    'packaging_id' => $item['packaging']?->id,
                    'in_packaging' => $item['packaging'] !== null,
                    'entered_unit' => $item['packaging']?->name ?? $item['article']->unit->symbol,
                    'factor' => $item['factor'],
                    'entered_quantity' => $item['entered_quantity'],
                    'quantity' => $item['quantity'],
                    'list_price' => $item['list_price'],
                    'unit_price' => $item['unit_price'],
                    'discount_type' => $item['discount_type'],
                    'discount_value' => $item['discount_value'],
                    'discount_amount' => $item['discount_amount'],
                    'gross_total' => $item['gross'],
                    'line_total' => $item['line_total'],
                    'cost_total' => $item['cost_total'],
                ]);
                $line->save();

                foreach ($item['allocations'] as $allocation) {
                    $this->stock->sell($sale, $line, $allocation['lot'], $allocation['quantity'], $allocation['cost']);
                }
            }

            foreach ($payments['rows'] as $row) {
                (new SalePayment)->forceFill([
                    'tenant_id' => $tenant->id,
                    'sale_id' => $sale->id,
                    'method' => $row['method'],
                    'amount' => $row['amount'],
                    'tendered' => $row['tendered'],
                    'change_given' => $row['change'],
                    'reference' => $row['reference'],
                    'paid_at' => now(),
                    'status' => 'valid',
                    'created_by' => auth()->id(),
                ])->save();
            }

            return $this->fresh($sale);
        });
    }

    /**
     * Ce qu'un client doit déjà sur ses ventes validées.
     */
    public function outstanding(Customer $customer): string
    {
        $tenant = app(CurrentTenant::class)->tenant();

        $due = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('status', 'validated')
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) AS due')
            ->value('due');

        return Money::normalize((string) $due, $tenant->currency);
    }

        /**
     * Ce que doit chaque client de la liste (ventes validées uniquement), en une seule requête.
     *
     * @param  list<string>  $customerIds
     * @return array<string, string>
     */
    public function balances(array $customerIds): array
    {
        $tenant = app(CurrentTenant::class)->tenant();
        $balances = array_fill_keys($customerIds, Money::normalize('0', $tenant->currency));

        if ($customerIds === []) {
            return $balances;
        }

        $rows = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'validated')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(total - amount_paid) AS due')
            ->get();

        foreach ($rows as $row) {
            $balances[$row->customer_id] = Money::normalize((string) $row->due, $tenant->currency);
        }

        return $balances;
    }

    /**
     * Annule une vente : le stock revient dans les lots exacts, les paiements sont annulés et tracés.
     */
    public function cancel(Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $reason): Sale {
            Tenant::query()->whereKey(app(CurrentTenant::class)->id())->lockForUpdate()->firstOrFail();
            $sale->refresh();

            if ($sale->status !== StockEntryStatus::Validated) {
                throw new BusinessRuleException('Cette vente est déjà annulée.', 'sale_not_validated');
            }

            $allocations = SaleAllocation::query()
                ->whereIn('sale_line_id', $sale->lines()->pluck('id'))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            foreach ($allocations as $allocation) {
                $this->stock->restoreSale($sale, $allocation);
            }

            $this->payments->voidAll($sale, mb_substr("Vente annulée : {$reason}", 0, 300));

            $sale->forceFill([
                'status' => StockEntryStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason,
                'amount_paid' => '0',
                'payment_status' => PaymentStatus::Unpaid,
            ])->save();

            return $this->fresh($sale);
        });
    }

    // ------------------------------------------------------------------ Lignes

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return array{lines: list<array<string, mixed>>, subtotal: string, discount_total: string, total: string, cost_total: string}
     */
    private function planLines(array $inputs, Warehouse $warehouse, string $saleDate, string $today, string $currency): array
    {
        $decimals = Money::decimals($currency);

        $articles = Article::query()
            ->with(['unit', 'packagings'])
            ->whereIn('id', array_column($inputs, 'article_id'))
            ->get()
            ->keyBy('id');

        $reserved = [];
        $lines = [];
        $subtotal = '0';
        $discountTotal = '0';
        $total = '0';
        $costTotal = '0';

        foreach (array_values($inputs) as $input) {
            $article = $articles->get($input['article_id']);

            if ($article === null || ! $article->is_active) {
                $name = $article?->name ?? 'cet article';

                throw new BusinessRuleException(
                    "L'article « {$name} » n'est plus disponible à la vente. Retirez la ligne.",
                    'article_inactive',
                );
            }

            $packaging = ! empty($input['packaging_id'])
                ? $article->packagings->firstWhere('id', $input['packaging_id'])
                : null;

            try {
                $converted = LineCalculator::convert($article, $packaging, (string) $input['entered_quantity']);
            } catch (DomainException $exception) {
                throw new BusinessRuleException($exception->getMessage(), 'invalid_quantity');
            }

            $listPrice = SalePricing::listPrice($article, $converted['factor'], $currency);
            $unitPrice = isset($input['unit_price']) ? Money::normalize((string) $input['unit_price'], $currency) : $listPrice;
            $gross = LineCalculator::total((string) $input['entered_quantity'], $unitPrice, $currency);

            $type = $input['discount_type'] ?? null;
            $value = isset($input['discount_value']) ? (string) $input['discount_value'] : null;
            $discount = SalePricing::discount($gross, $type, $value, $currency);

            if (bccomp($discount, $gross, $decimals) > 0) {
                throw new BusinessRuleException(
                    "La remise de « {$article->name} » dépasse le total de la ligne.",
                    'discount_exceeds_total',
                );
            }

            $lineTotal = bcsub($gross, $discount, $decimals);

            $allocations = [];
            $lineCost = '0';

            foreach ($this->picker->pick($article, $warehouse, $converted['quantity'], $today, $reserved) as [$lot, $quantity]) {
                if ($saleDate < $lot->received_date->toDateString()) {
                    throw new BusinessRuleException(
                        "La date de la vente est antérieure à la réception du lot {$lot->code} (le {$lot->received_date->format('d/m/Y')}). Choisissez une date plus récente.",
                        'sale_before_reception',
                    );
                }

                $cost = Money::round(bcmul($quantity, (string) $lot->unit_cost, 9), $decimals);

                $allocations[] = ['lot' => $lot, 'quantity' => $quantity, 'cost' => $cost];
                $lineCost = bcadd($lineCost, $cost, $decimals);
            }

            $lines[] = [
                'article' => $article,
                'packaging' => $packaging,
                'factor' => $converted['factor'],
                'quantity' => $converted['quantity'],
                'entered_quantity' => (string) $input['entered_quantity'],
                'list_price' => $listPrice,
                'unit_price' => $unitPrice,
                'discount_type' => $type,
                'discount_value' => $type !== null ? $value : null,
                'discount_amount' => $discount,
                'gross' => $gross,
                'line_total' => $lineTotal,
                'cost_total' => $lineCost,
                'allocations' => $allocations,
            ];

            $subtotal = bcadd($subtotal, $gross, $decimals);
            $discountTotal = bcadd($discountTotal, $discount, $decimals);
            $total = bcadd($total, $lineTotal, $decimals);
            $costTotal = bcadd($costTotal, $lineCost, $decimals);
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'total' => $total,
            'cost_total' => $costTotal,
        ];
    }

    // --------------------------------------------------------------- Paiements

    /**
     * Les espèces peuvent dépasser le restant dû : le surplus est rendu. Les autres modes, non.
     *
     * @param  list<array<string, mixed>>  $inputs
     * @return array{rows: list<array<string, mixed>>, paid: string}
     */
    private function planPayments(array $inputs, string $total, string $currency): array
    {
        $decimals = Money::decimals($currency);
        $remaining = $total;
        $rows = [];

        foreach ($inputs as $input) {
            $method = PaymentMethod::from($input['method']);
            $received = Money::normalize((string) $input['amount'], $currency);

            if (bccomp($remaining, '0', $decimals) <= 0) {
                throw new BusinessRuleException(
                    'La vente est déjà entièrement payée : retirez ce paiement.',
                    'sale_already_paid',
                );
            }

            $applied = $received;
            $tendered = null;
            $change = '0';

            if (bccomp($received, $remaining, $decimals) > 0) {
                if (! $method->isCash()) {
                    throw new BusinessRuleException(
                        "Le paiement par {$method->label()} dépasse le montant restant à payer ({$remaining} {$currency}).",
                        'payment_exceeds_balance',
                    );
                }

                $applied = $remaining;
            }

            if ($method->isCash()) {
                $tendered = $received;
                $change = bcsub($received, $applied, $decimals);
            }

            $rows[] = [
                'method' => $method,
                'amount' => $applied,
                'tendered' => $tendered,
                'change' => $change,
                'reference' => $input['reference'] ?? null,
            ];

            $remaining = bcsub($remaining, $applied, $decimals);
        }

        return ['rows' => $rows, 'paid' => bcsub($total, $remaining, $decimals)];
    }

    // ------------------------------------------------------------------ Crédit

    private function assertCreditAllowed(?Customer $customer, string $balance, string $currency): void
    {
        if ($customer === null) {
            throw new BusinessRuleException(
                'Choisissez un client : une vente à crédit doit être rattachée à un client.',
                'customer_required',
            );
        }

        if ($customer->credit_limit === null) {
            return;
        }

        $decimals = Money::decimals($currency);
        $limit = Money::normalize((string) $customer->credit_limit, $currency);

        if (bccomp($limit, '0', $decimals) === 0) {
            throw new BusinessRuleException(
                "« {$customer->name} » n'a pas droit au crédit : son plafond est à zéro.",
                'credit_limit_exceeded',
            );
        }

        $after = bcadd($this->outstanding($customer), $balance, $decimals);

        if (bccomp($after, $limit, $decimals) > 0) {
            throw new BusinessRuleException(
                "Cette vente porterait ce que « {$customer->name} » vous doit à {$after} {$currency}, au-delà de son plafond de crédit de {$limit} {$currency}.",
                'credit_limit_exceeded',
            );
        }
    }

    private function fresh(Sale $sale): Sale
    {
        return $sale->refresh()->load([
            'warehouse', 'customer', 'creator:id,name', 'canceller:id,name',
            'lines.article.unit', 'lines.allocations.lot', 'payments.creator:id,name',
        ]);
    }
}