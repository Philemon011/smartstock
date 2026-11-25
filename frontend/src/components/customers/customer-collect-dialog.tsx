"use client";

import { useState } from "react";
import { toast } from "sonner";
import { useActiveMembership } from "@/components/session/session-provider";
import { AffixInput } from "@/components/ui/affix-input";
import { Badge } from "@/components/ui/badge";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { useSaleMeta, useSaleMutations, useSales } from "@/hooks/use-sales";
import { ApiError } from "@/lib/api/client";
import type { Customer } from "@/lib/api/customers";
import type { Sale } from "@/lib/api/sales";
import { parseDecimal, type Decimal } from "@/lib/decimal";
import { currencyDecimals, formatDate, formatMoney } from "@/lib/format";
import { compare, normalizeNumber, subtract, toMoney, zero } from "@/lib/sale-math";
import { pluralize } from "@/lib/session";

const MAX_PREVIEW = 6;

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

const isPositive = (value: string): boolean => /[1-9]/.test(value);

type PreviewRow = { id: string; number: string; date: string; take: Decimal; settled: boolean };

/** Répartition prévue : les ventes les plus anciennes d'abord, comme le serveur. */
function distribute(sales: Sale[], applied: Decimal, decimals: number): PreviewRow[] {
  const ordered = [...sales].sort((a, b) =>
    a.sale_date === b.sale_date ? a.number.localeCompare(b.number) : a.sale_date.localeCompare(b.sale_date),
  );

  let remaining = applied;
  const rows: PreviewRow[] = [];

  for (const sale of ordered) {
    if (compare(remaining, zero(decimals)) <= 0) break;

    const balance = parseDecimal(sale.balance_due);

    if (!balance) continue;

    const take = compare(balance, remaining) <= 0 ? balance : remaining;

    rows.push({
      id: sale.id,
      number: sale.number,
      date: sale.sale_date,
      take,
      settled: compare(take, balance) === 0,
    });
    remaining = subtract(remaining, take);
  }

  return rows;
}

type CustomerCollectDialogProps = {
  customer: Customer;
  onClose: () => void;
};

export function CustomerCollectDialog({ customer, onClose }: CustomerCollectDialogProps) {
  const { tenant } = useActiveMembership();
  const currency = tenant.currency;
  const decimals = currencyDecimals(currency);

  const meta = useSaleMeta();
  const mutations = useSaleMutations();
  const sales = useSales({
    search: "",
    status: "all",
    paymentStatus: "all",
    customerId: customer.id,
    warehouseId: null,
    hasBalance: true,
    from: "",
    to: "",
    page: 1,
  });

  const due = customer.balance_due ?? "0";
  const dueDecimal = parseDecimal(due) ?? zero(decimals);

  const [method, setMethod] = useState("cash");
  const [amount, setAmount] = useState(due);
  const [reference, setReference] = useState("");
  const [errors, setErrors] = useState<{ method?: string; amount?: string; reference?: string }>({});
  const [error, setError] = useState<string | null>(null);

  const methods = meta.data?.payment_methods ?? [];
  const info = methods.find((item) => item.value === method);
  const isCash = info?.is_cash ?? false;

  const parsed = parseDecimal(amount);
  const exceeds = parsed !== null && compare(parsed, dueDecimal) > 0;
  const applied = parsed === null ? null : exceeds ? (isCash ? dueDecimal : null) : parsed;
  const change = isCash && parsed !== null && exceeds ? subtract(parsed, dueDecimal) : null;

  const allLoaded = sales.data !== undefined && sales.data.meta.total <= sales.data.data.length;
  const preview =
    allLoaded && sales.data && applied !== null && compare(applied, zero(decimals)) > 0
      ? distribute(sales.data.data, applied, decimals)
      : [];

  const amountError =
    errors.amount ??
    (exceeds && !isCash
      ? "Ce paiement dépasse ce que le client doit : seules les espèces donnent lieu à un rendu de monnaie."
      : undefined);

  const money = (value: Decimal) => formatMoney(toMoney(value, decimals), currency);

  async function confirm() {
    setErrors({});
    setError(null);

    try {
      const result = await mutations.collectForCustomer.mutateAsync({
        customerId: customer.id,
        input: {
          method,
          amount: normalizeNumber(amount),
          reference: isCash ? null : emptyToNull(reference),
        },
      });

      const changeText = isPositive(result.change_given)
        ? ` Rendez ${formatMoney(result.change_given, currency)} au client.`
        : "";

      toast.success(
        `${formatMoney(result.applied, currency)} encaissés sur ${pluralize(result.sales.length, "vente", "ventes")}.${changeText}`,
      );
      onClose();
    } catch (caught) {
      if (caught instanceof ApiError && caught.code === "validation_error") {
        const next = {
          method: caught.fieldErrors.method?.[0],
          amount: caught.fieldErrors.amount?.[0],
          reference: caught.fieldErrors.reference?.[0],
        };

        if (next.method || next.amount || next.reference) {
          setErrors(next);
          return;
        }
      }

      setError(caught instanceof ApiError ? caught.message : "Une erreur inattendue est survenue. Réessayez.");
    }
  }

  return (
    <ConfirmDialog
      open
      onOpenChange={(next) => {
        if (!next && !mutations.collectForCustomer.isPending) onClose();
      }}
      title="Encaisser un paiement"
      description={`${customer.name} doit ${formatMoney(due, currency)}. Le paiement solde ses ventes les plus anciennes en premier.`}
      confirmLabel="Encaisser"
      pending={mutations.collectForCustomer.isPending}
      onConfirm={() => void confirm()}
      error={error}
    >
      <div className="space-y-4">
        <Field label="Mode de paiement" error={errors.method}>
          <Select
            size="lg"
            options={methods.map((item) => ({ value: item.value, label: item.label }))}
            value={method}
            onValueChange={(value) => {
              setMethod(value);
              setErrors({});
            }}
          />
        </Field>

        <Field label={isCash ? "Montant reçu" : "Montant"} error={amountError}>
          <AffixInput
            size="lg"
            inputMode="decimal"
            autoComplete="off"
            suffix={currency}
            value={amount}
            onChange={(event) => {
              setAmount(event.target.value);
              setErrors((previous) => ({ ...previous, amount: undefined }));
            }}
          />
        </Field>

        {change ? (
          <p className="rounded-md bg-success-soft px-3 py-2 text-sm text-success">
            Rendu de monnaie : <span className="font-semibold tabular-nums">{money(change)}</span>
          </p>
        ) : null}

        {!isCash && info ? (
          <Field label="N° de transaction" optional error={errors.reference}>
            <Input
              size="lg"
              autoComplete="off"
              value={reference}
              onChange={(event) => setReference(event.target.value)}
            />
          </Field>
        ) : null}

        {preview.length > 0 ? (
          <div className="space-y-2">
            <p className="text-sm font-medium text-foreground">Ventes concernées</p>

            <ul className="divide-y rounded-lg border bg-background">
              {preview.slice(0, MAX_PREVIEW).map((row) => (
                <li key={row.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                  <div className="min-w-0">
                    <p className="font-medium text-foreground">{row.number}</p>
                    <p className="text-xs text-muted-foreground">{formatDate(row.date)}</p>
                  </div>

                  <div className="flex items-center gap-3">
                    <span className="tabular-nums text-foreground">{money(row.take)}</span>
                    <Badge variant={row.settled ? "success" : "warning"}>
                      {row.settled ? "Soldée" : "Partielle"}
                    </Badge>
                  </div>
                </li>
              ))}
            </ul>

            {preview.length > MAX_PREVIEW ? (
              <p className="text-xs text-muted-foreground">
                … et {pluralize(preview.length - MAX_PREVIEW, "autre vente", "autres ventes")}.
              </p>
            ) : null}
          </div>
        ) : null}
      </div>
    </ConfirmDialog>
  );
}