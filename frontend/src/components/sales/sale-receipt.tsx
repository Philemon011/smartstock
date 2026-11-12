"use client";

import { ArrowLeft, Printer, Receipt } from "lucide-react";
import Link from "next/link";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { Skeleton } from "@/components/ui/skeleton";
import { useSale } from "@/hooks/use-sales";
import { ApiError } from "@/lib/api/client";
import { formatDate, formatDateTime, formatMoney, formatQuantity } from "@/lib/format";

const PRINT_CSS = `
@page { size: 80mm auto; margin: 4mm; }
@media print {
  body * { visibility: hidden !important; }
  #sale-receipt, #sale-receipt * { visibility: visible !important; color: #000 !important; }
  #sale-receipt {
    position: absolute; left: 0; top: 0; width: 72mm; max-width: 72mm;
    margin: 0 !important; padding: 0 !important; border: 0 !important;
    box-shadow: none !important; background: #fff !important;
  }
}
`;

const isPositive = (value: string): boolean => /[1-9]/.test(value);

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={`flex justify-between gap-3 ${strong ? "text-base font-semibold" : ""}`}>
      <span>{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  );
}

export function SaleReceipt({ id }: { id: string }) {
  const { tenant } = useActiveMembership();
  const query = useSale(id);
  const currency = tenant.currency;

  if (query.isPending) {
    return <Skeleton className="mx-auto h-96 w-full max-w-sm rounded-xl" />;
  }

  if (query.isError) {
    const notFound = query.error instanceof ApiError && query.error.status === 404;

    return notFound ? (
      <EmptyState
        icon={Receipt}
        title="Vente introuvable"
        description="Cette vente n'existe pas."
        action={
          <Link href="/sales" className={buttonVariants({ variant: "secondary" })}>
            Retour aux ventes
          </Link>
        }
      />
    ) : (
      <Alert variant="danger" title="Impossible de charger le ticket">
        {query.error instanceof ApiError ? query.error.message : "Une erreur inattendue est survenue. Réessayez."}
      </Alert>
    );
  }

  const sale = query.data;
  const lines = sale.lines ?? [];
  const payments = (sale.payments ?? []).filter((payment) => payment.status === "valid");
  const cancelled = sale.status === "cancelled";
  const hasDiscount = isPositive(sale.discount_total);
  const change = payments.find((payment) => isPositive(payment.change_given));

  return (
    <div className="mx-auto max-w-sm space-y-4">
      <style>{PRINT_CSS}</style>

      <div className="flex items-center justify-between gap-3 print:hidden">
        <Link
          href={`/sales/${sale.id}`}
          className="inline-flex items-center gap-2 text-sm text-muted-foreground transition-colors duration-200 ease-smooth hover:text-foreground"
        >
          <ArrowLeft className="size-4" strokeWidth={1.5} aria-hidden />
          Retour à la vente
        </Link>

        <Button onClick={() => window.print()}>
          <Printer strokeWidth={1.5} aria-hidden />
          Imprimer
        </Button>
      </div>

      <article id="sale-receipt" className="space-y-4 rounded-xl border bg-card p-6 text-sm text-foreground shadow-card">
        <header className="space-y-1 text-center">
          <h1 className="font-display text-xl font-semibold tracking-heading">{tenant.name}</h1>
          <p className="text-xs text-muted-foreground">Ticket de vente</p>
          <p className="font-medium">{sale.number}</p>
          <p className="text-xs text-muted-foreground">
            {sale.created_at ? formatDateTime(sale.created_at) : formatDate(sale.sale_date)}
          </p>
        </header>

        {cancelled ? (
          <p className="rounded-md border border-danger px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-danger">
            Vente annulée
          </p>
        ) : null}

        <div className="space-y-0.5 text-xs text-muted-foreground">
          {sale.customer ? <p>Client : {sale.customer.name}</p> : null}
          {sale.created_by ? <p>Servi par : {sale.created_by}</p> : null}
        </div>

        <ul className="space-y-3 border-y border-dashed py-4">
          {lines.map((line) => (
            <li key={line.id}>
              <p className="font-medium">{line.article.name}</p>
              <div className="flex justify-between gap-3 text-xs text-muted-foreground">
                <span>
                  {formatQuantity(line.entered_quantity)} {line.entered_unit} × {formatMoney(line.unit_price, currency)}
                </span>
                <span className="tabular-nums text-foreground">{formatMoney(line.gross_total, currency)}</span>
              </div>
              {line.discount ? (
                <div className="flex justify-between gap-3 text-xs text-muted-foreground">
                  <span>
                    Remise{" "}
                    {line.discount.type === "percent"
                      ? `${formatQuantity(line.discount.value)} %`
                      : formatMoney(line.discount.value, currency)}
                  </span>
                  <span className="tabular-nums">− {formatMoney(line.discount.amount, currency)}</span>
                </div>
              ) : null}
            </li>
          ))}
        </ul>

        <div className="space-y-1.5">
          {hasDiscount ? (
            <>
              <Row label="Sous-total" value={formatMoney(sale.subtotal, currency)} />
              <Row label="Remises" value={`− ${formatMoney(sale.discount_total, currency)}`} />
            </>
          ) : null}
          <Row label="TOTAL" value={formatMoney(sale.total, currency)} strong />
        </div>

        {!cancelled && payments.length > 0 ? (
          <div className="space-y-1.5 border-t border-dashed pt-4">
            {payments.map((payment) => (
              <Row key={payment.id} label={payment.method_label} value={formatMoney(payment.amount, currency)} />
            ))}
            {change && change.tendered ? (
              <>
                <Row label="Espèces reçues" value={formatMoney(change.tendered, currency)} />
                <Row label="Rendu de monnaie" value={formatMoney(change.change_given, currency)} />
              </>
            ) : null}
          </div>
        ) : null}

        {!cancelled && isPositive(sale.balance_due) ? (
          <div className="border-t border-dashed pt-4">
            <Row label="Reste à payer" value={formatMoney(sale.balance_due, currency)} strong />
          </div>
        ) : null}

        <p className="border-t border-dashed pt-4 text-center text-xs text-muted-foreground">
          Merci de votre confiance.
        </p>
      </article>
    </div>
  );
}