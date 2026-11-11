<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockEntryStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SalePaymentService
{
    /**
     * Encaisse un paiement sur une vente validée (le reste d'une vente à crédit).
     *
     * @param  array<string, mixed>  $data
     */
    public function collect(Sale $sale, array $data): Sale
    {
        return DB::transaction(function () use ($sale, $data): Sale {
            $this->lockTenant();
            $sale->refresh();

            $this->assertValidated($sale, 'Cette vente est annulée : elle ne peut plus recevoir de paiement.');

            $currency = app(CurrentTenant::class)->tenant()->currency;
            $decimals = Money::decimals($currency);
            $balance = bcsub((string) $sale->total, (string) $sale->amount_paid, $decimals);

            if (bccomp($balance, '0', $decimals) <= 0) {
                throw new BusinessRuleException('Cette vente est déjà entièrement payée.', 'sale_already_paid');
            }

            $method = PaymentMethod::from($data['method']);
            $row = $this->plan($method, Money::normalize((string) $data['amount'], $currency), $balance, $currency, 'le montant restant à payer');

            $this->record($sale, $method, $row, $data['reference'] ?? null);
            $this->refreshTotals($sale, $currency);

            return $this->fresh($sale);
        });
    }

    /**
     * Annule un paiement : la dette de la vente est recalculée.
     */
    public function void(Sale $sale, SalePayment $payment, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $payment, $reason): Sale {
            $this->lockTenant();
            $sale->refresh();

            $this->assertValidated($sale, "Les paiements d'une vente annulée ne peuvent plus être modifiés.");

            if ($payment->sale_id !== $sale->id) {
                throw (new ModelNotFoundException)->setModel(SalePayment::class);
            }

            $payment->refresh();

            if ($payment->status !== 'valid') {
                throw new BusinessRuleException('Ce paiement est déjà annulé.', 'payment_already_voided');
            }

            $this->voidPayment($payment, $reason);
            $this->refreshTotals($sale, app(CurrentTenant::class)->tenant()->currency);

            return $this->fresh($sale);
        });
    }

    /**
     * Annule tous les paiements valides d'une vente qu'on annule.
     */
    public function voidAll(Sale $sale, string $reason): void
    {
        $payments = SalePayment::query()->where('sale_id', $sale->id)->where('status', 'valid')->get();

        foreach ($payments as $payment) {
            $this->voidPayment($payment, $reason);
        }
    }

    /**
     * Un client paie une somme globale : elle solde ses ventes impayées, de la plus ancienne à la plus récente.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function collectForCustomer(Customer $customer, array $data): array
    {
        return DB::transaction(function () use ($customer, $data): array {
            $this->lockTenant();

            $currency = app(CurrentTenant::class)->tenant()->currency;
            $decimals = Money::decimals($currency);

            $sales = Sale::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'validated')
                ->whereColumn('total', '>', 'amount_paid')
                ->orderBy('sale_date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($sales->isEmpty()) {
                throw new BusinessRuleException(
                    "« {$customer->name} » ne doit rien : aucune vente impayée.",
                    'nothing_due',
                );
            }

            $due = $sales->reduce(
                fn (string $sum, Sale $sale) => bcadd($sum, bcsub((string) $sale->total, (string) $sale->amount_paid, $decimals), $decimals),
                '0',
            );

            $method = PaymentMethod::from($data['method']);
            $plan = $this->plan($method, Money::normalize((string) $data['amount'], $currency), $due, $currency, 'ce que ce client doit');

            $remaining = $plan['amount'];
            $settled = [];

            foreach ($sales as $sale) {
                if (bccomp($remaining, '0', $decimals) <= 0) {
                    break;
                }

                $balance = bcsub((string) $sale->total, (string) $sale->amount_paid, $decimals);
                $take = bccomp($balance, $remaining, $decimals) <= 0 ? $balance : $remaining;

                $settled[] = [$sale, $take];
                $remaining = bcsub($remaining, $take, $decimals);
            }

            $result = [];
            $lastIndex = array_key_last($settled);

            foreach ($settled as $index => [$sale, $take]) {
                $isLast = $index === $lastIndex;

                // Les espèces rendues sont portées par la dernière ligne : tendered - amount = change_given.
                $this->record($sale, $method, [
                    'amount' => $take,
                    'tendered' => $method->isCash() ? bcadd($take, $isLast ? $plan['change'] : '0', $decimals) : null,
                    'change' => $method->isCash() && $isLast ? $plan['change'] : '0',
                ], $data['reference'] ?? null);

                $this->refreshTotals($sale, $currency);

                $result[] = [
                    'id' => $sale->id,
                    'number' => $sale->number,
                    'amount' => $take,
                    'payment_status' => $sale->payment_status->value,
                ];
            }

            return [
                'customer_id' => $customer->id,
                'applied' => $plan['amount'],
                'change_given' => $plan['change'],
                'outstanding_after' => bcsub($due, $plan['amount'], $decimals),
                'sales' => $result,
            ];
        });
    }

    // ---------------------------------------------------------------- Outils

    /**
     * Les espèces peuvent dépasser ce qui est dû : le surplus est rendu. Les autres modes, non.
     *
     * @return array{amount: string, tendered: string|null, change: string}
     */
    private function plan(PaymentMethod $method, string $received, string $due, string $currency, string $subject): array
    {
        $decimals = Money::decimals($currency);
        $applied = $received;

        if (bccomp($received, $due, $decimals) > 0) {
            if (! $method->isCash()) {
                throw new BusinessRuleException(
                    "Le paiement par {$method->label()} dépasse {$subject} ({$due} {$currency}).",
                    'payment_exceeds_balance',
                );
            }

            $applied = $due;
        }

        return [
            'amount' => $applied,
            'tendered' => $method->isCash() ? $received : null,
            'change' => $method->isCash() ? bcsub($received, $applied, $decimals) : '0',
        ];
    }

    /**
     * @param  array{amount: string, tendered: string|null, change: string}  $row
     */
    private function record(Sale $sale, PaymentMethod $method, array $row, ?string $reference): void
    {
        (new SalePayment)->forceFill([
            'tenant_id' => $sale->tenant_id,
            'sale_id' => $sale->id,
            'method' => $method,
            'amount' => $row['amount'],
            'tendered' => $row['tendered'],
            'change_given' => $row['change'],
            'reference' => $reference,
            'paid_at' => now(),
            'status' => 'valid',
            'created_by' => auth()->id(),
        ])->save();
    }

    private function voidPayment(SalePayment $payment, string $reason): void
    {
        $payment->forceFill([
            'status' => 'voided',
            'voided_at' => now(),
            'voided_by' => auth()->id(),
            'void_reason' => $reason,
        ])->save();
    }

    /**
     * Le total payé et le statut de paiement se recalculent toujours à partir des paiements valides.
     */
    private function refreshTotals(Sale $sale, string $currency): void
    {
        $decimals = Money::decimals($currency);

        $paid = Money::normalize((string) DB::table('sale_payments')
            ->where('sale_id', $sale->id)
            ->where('status', 'valid')
            ->selectRaw('COALESCE(SUM(amount), 0) AS paid')
            ->value('paid'), $currency);

        $total = Money::normalize((string) $sale->total, $currency);

        $status = bccomp($paid, $total, $decimals) === 0
            ? PaymentStatus::Paid
            : (bccomp($paid, '0', $decimals) > 0 ? PaymentStatus::Partial : PaymentStatus::Unpaid);

        $sale->forceFill(['amount_paid' => $paid, 'payment_status' => $status])->save();
    }

    private function assertValidated(Sale $sale, string $message): void
    {
        if ($sale->status !== StockEntryStatus::Validated) {
            throw new BusinessRuleException($message, 'sale_not_validated');
        }
    }

    private function lockTenant(): void
    {
        Tenant::query()->whereKey(app(CurrentTenant::class)->id())->lockForUpdate()->firstOrFail();
    }

    private function fresh(Sale $sale): Sale
    {
        return $sale->refresh()->load([
            'warehouse', 'customer', 'creator:id,name', 'canceller:id,name',
            'lines.article.unit', 'lines.allocations.lot', 'payments.creator:id,name',
        ]);
    }
}