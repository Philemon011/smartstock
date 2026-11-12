"use client";

import { useState } from "react";
import { SaleForm } from "@/components/sales/sale-form";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import type { Sale } from "@/lib/api/sales";
import { add, parseDecimal } from "@/lib/decimal";
import { currencyDecimals, formatMoney } from "@/lib/format";
import { compare, toMoney, zero } from "@/lib/sale-math";
import { CircleCheck, Printer, Receipt } from "lucide-react"
import Link from "next/link";

const STATUS_VARIANT = { paid: "success", partial: "warning", unpaid: "danger" } as const;

function SaleSuccess({ sale, onNew }: { sale: Sale; onNew: () => void }) {
  const { tenant } = useActiveMembership();
  const currency = tenant.currency;
  const decimals = currencyDecimals(currency);

  const change = (sale.payments ?? []).reduce(
    (sum, payment) => add(sum, parseDecimal(payment.change_given) ?? zero(decimals)),
    zero(decimals),
  );
  const hasChange = compare(change, zero(decimals)) > 0;
  const hasBalance = (parseDecimal(sale.balance_due) ?? zero(decimals)).value > BigInt(0);

  return (
    <section className="mx-auto max-w-xl space-y-6 rounded-xl border bg-card p-6 text-center shadow-card sm:p-8">
      <span className="mx-auto flex size-14 items-center justify-center rounded-full bg-success-soft text-success">
        <CircleCheck className="size-7" strokeWidth={1.5} aria-hidden />
      </span>

      <div className="space-y-1">
        <h2 className="text-2xl">Vente enregistrée</h2>
        <p className="text-sm text-muted-foreground">
          {sale.number}
          {sale.customer ? ` · ${sale.customer.name}` : ""}
        </p>
      </div>

      <div className="space-y-1">
        <p className="text-sm text-muted-foreground">Total</p>
        <p className="text-4xl font-semibold tabular-nums tracking-display text-foreground">
          {formatMoney(sale.total, currency)}
        </p>
        <div className="pt-1">
          <Badge variant={STATUS_VARIANT[sale.payment_status]}>{sale.payment_status_label}</Badge>
        </div>
      </div>

      {hasChange ? (
        <div className="rounded-lg bg-success-soft p-4 text-success">
          <p className="text-sm">Rendu de monnaie à remettre au client</p>
          <p className="mt-1 text-3xl font-semibold tabular-nums">{formatMoney(toMoney(change, decimals), currency)}</p>
        </div>
      ) : null}

      {hasBalance ? (
        <Alert variant="warning" title="Vente à crédit">
          Il reste {formatMoney(sale.balance_due, currency)} à payer
          {sale.customer ? ` par ${sale.customer.name}` : ""}.
        </Alert>
      ) : null}

            <Button size="lg" className="w-full" onClick={onNew}>
        Nouvelle vente
      </Button>

      <div className="grid gap-3 sm:grid-cols-2">
        <Link href={`/sales/${sale.id}`} className={buttonVariants({ variant: "secondary", size: "lg" })}>
          <span className="inline-flex items-center gap-2">
            <Receipt className="size-4" strokeWidth={1.5} aria-hidden />
            Voir la vente
          </span>
        </Link>
        <Link
          href={`/sales/${sale.id}/receipt`}
          target="_blank"
          rel="noopener noreferrer"
          className={buttonVariants({ variant: "secondary", size: "lg" })}
        >
          <span className="inline-flex items-center gap-2">
            <Printer className="size-4" strokeWidth={1.5} aria-hidden />
            Imprimer le ticket
          </span>
        </Link>
      </div>
    </section>
  );
}

export function SaleRegister() {
  const { abilities } = useActiveMembership();
  const [completed, setCompleted] = useState<Sale | null>(null);
  const [formKey, setFormKey] = useState(0);
  const [warehouseId, setWarehouseId] = useState<string | null>(null);

  if (!abilities.sell) {
    return (
      <Alert variant="danger" title="Accès réservé">
        Seuls le propriétaire, les administrateurs et les vendeurs peuvent enregistrer des ventes.
      </Alert>
    );
  }

  if (completed) {
    return (
      <SaleSuccess
        sale={completed}
        onNew={() => {
          setCompleted(null);
          setFormKey((key) => key + 1);
        }}
      />
    );
  }

  return (
    <SaleForm
      key={formKey}
      warehouseId={warehouseId}
      onWarehouseChange={setWarehouseId}
      onCompleted={setCompleted}
    />
  );
}