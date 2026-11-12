"use client";

import { ArrowLeft, Banknote, Printer, Receipt, Undo2 } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";
import { SaleStatusBadge } from "@/components/sales/payment-status-badge";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { AffixInput } from "@/components/ui/affix-input";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useSale, useSaleMeta, useSaleMutations } from "@/hooks/use-sales";
import { ApiError } from "@/lib/api/client";
import type { Sale, SaleLine, SalePayment } from "@/lib/api/sales";
import { parseDecimal } from "@/lib/decimal";
import { currencyDecimals, formatDate, formatDateTime, formatMoney, formatQuantity } from "@/lib/format";
import { compare, normalizeNumber, subtract, toMoney, zero } from "@/lib/sale-math";

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

const isPositive = (value: string): boolean => /[1-9]/.test(value);

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

function discountLabel(line: SaleLine, currency: string): string | null {
  if (!line.discount) return null;

  const value =
    line.discount.type === "percent"
      ? `${formatQuantity(line.discount.value)} %`
      : formatMoney(line.discount.value, currency);

  return `Remise ${value}`;
}

function LotsCell({ line }: { line: SaleLine }) {
  return (
    <ul className="space-y-2">
      {line.allocations.map((allocation) => (
        <li key={allocation.id} className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <Badge>{allocation.lot.code}</Badge>
          <span className="text-sm tabular-nums text-foreground">
            {formatQuantity(allocation.quantity)} {line.article.unit.symbol}
          </span>
          {allocation.lot.expiry_date ? (
            <span className="text-xs text-muted-foreground">exp. {formatDate(allocation.lot.expiry_date)}</span>
          ) : null}
        </li>
      ))}
    </ul>
  );
}

function PriceCell({ line, currency }: { line: SaleLine; currency: string }) {
  const changed = line.list_price !== line.unit_price;

  return (
    <>
      <p className="text-foreground">
        {formatMoney(line.unit_price, currency)}
        <span className="text-xs text-muted-foreground"> / {line.entered_unit}</span>
      </p>
      {changed ? (
        <p className="mt-0.5 text-xs text-muted-foreground">
          Prix catalogue {formatMoney(line.list_price, currency)}
        </p>
      ) : null}
    </>
  );
}

function TotalCell({ line, currency }: { line: SaleLine; currency: string }) {
  const label = discountLabel(line, currency);

  return (
    <>
      <p className="font-medium tabular-nums text-foreground">{formatMoney(line.line_total, currency)}</p>
      {label && line.discount ? (
        <p className="mt-0.5 text-xs text-muted-foreground">
          {label} (− {formatMoney(line.discount.amount, currency)})
        </p>
      ) : null}
    </>
  );
}

function quantityLabel(line: SaleLine): string {
  return `${formatQuantity(line.entered_quantity)} ${line.entered_unit}`;
}

function stockQuantityLabel(line: SaleLine): string | null {
  return line.entered_unit === line.article.unit.symbol
    ? null
    : `= ${formatQuantity(line.quantity)} ${line.article.unit.symbol}`;
}

function LinesTable({ lines, currency }: { lines: SaleLine[]; currency: string }) {
  const showCost = lines.some((line) => line.cost_total !== undefined);

  return (
    <div className="hidden overflow-x-auto md:block">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b text-xs text-muted-foreground">
            <th className="h-11 pr-4 font-medium">Article</th>
            <th className="pr-4 font-medium">Quantité</th>
            <th className="pr-4 font-medium">Prix</th>
            <th className="pr-4 font-medium">Lots vendus</th>
            {showCost ? <th className="pr-4 text-right font-medium">Coût</th> : null}
            <th className="text-right font-medium">Total</th>
          </tr>
        </thead>
        <tbody>
          {lines.map((line) => (
            <tr key={line.id} className="border-b align-top last:border-b-0">
              <td className="py-4 pr-4">
                <Link
                  href={`/articles/${line.article.id}`}
                  className="font-medium text-foreground underline-offset-4 hover:underline"
                >
                  {line.article.name}
                </Link>
                <p className="mt-0.5 text-xs text-muted-foreground">{line.article.code}</p>
              </td>
              <td className="py-4 pr-4 tabular-nums">
                <p className="text-foreground">{quantityLabel(line)}</p>
                {stockQuantityLabel(line) ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">{stockQuantityLabel(line)}</p>
                ) : null}
              </td>
              <td className="py-4 pr-4 tabular-nums">
                <PriceCell line={line} currency={currency} />
              </td>
              <td className="py-4 pr-4">
                <LotsCell line={line} />
              </td>
              {showCost ? (
                <td className="py-4 pr-4 text-right tabular-nums text-muted-foreground">
                  {line.cost_total !== undefined ? formatMoney(line.cost_total, currency) : "—"}
                </td>
              ) : null}
              <td className="py-4 text-right">
                <TotalCell line={line} currency={currency} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function LinesCards({ lines, currency }: { lines: SaleLine[]; currency: string }) {
  return (
    <ul className="space-y-3 md:hidden">
      {lines.map((line) => (
        <li key={line.id} className="rounded-lg border bg-background p-4">
          <Link
            href={`/articles/${line.article.id}`}
            className="font-medium text-foreground underline-offset-4 hover:underline"
          >
            {line.article.name}
          </Link>
          <p className="mt-0.5 text-xs text-muted-foreground">{line.article.code}</p>

          <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
            <Detail label="Quantité">
              {quantityLabel(line)}
              {stockQuantityLabel(line) ? (
                <span className="block text-xs font-normal text-muted-foreground">{stockQuantityLabel(line)}</span>
              ) : null}
            </Detail>
            <Detail label="Prix">
              <PriceCell line={line} currency={currency} />
            </Detail>
          </dl>

          <div className="mt-3">
            <p className="text-sm text-muted-foreground">Lots vendus</p>
            <div className="mt-1">
              <LotsCell line={line} />
            </div>
          </div>

          <div className="mt-3 border-t pt-3 text-right">
            <TotalCell line={line} currency={currency} />
          </div>
        </li>
      ))}
    </ul>
  );
}

function Totals({ sale, currency }: { sale: Sale; currency: string }) {
  const hasDiscount = isPositive(sale.discount_total);
  const cancelled = sale.status === "cancelled";

  return (
    <dl className="ml-auto w-full max-w-sm space-y-2 text-sm">
      {hasDiscount ? (
        <>
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">Sous-total</dt>
            <dd className="tabular-nums text-foreground">{formatMoney(sale.subtotal, currency)}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">Remises</dt>
            <dd className="tabular-nums text-foreground">− {formatMoney(sale.discount_total, currency)}</dd>
          </div>
        </>
      ) : null}

      <div className="flex items-end justify-between gap-4 border-t pt-3">
        <dt className="text-muted-foreground">Total</dt>
        <dd className="text-2xl font-semibold tabular-nums tracking-display text-foreground">
          {formatMoney(sale.total, currency)}
        </dd>
      </div>

      {!cancelled ? (
        <>
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">Payé</dt>
            <dd className="tabular-nums text-foreground">{formatMoney(sale.amount_paid, currency)}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">Reste à payer</dt>
            <dd
              className={
                isPositive(sale.balance_due)
                  ? "font-semibold tabular-nums text-warning"
                  : "tabular-nums text-foreground"
              }
            >
              {formatMoney(sale.balance_due, currency)}
            </dd>
          </div>
        </>
      ) : null}

      {sale.margin !== undefined && sale.cost_total !== undefined && !cancelled ? (
        <div className="space-y-2 border-t pt-3">
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">Coût d&apos;achat</dt>
            <dd className="tabular-nums text-muted-foreground">{formatMoney(sale.cost_total, currency)}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">Marge</dt>
            <dd className="font-medium tabular-nums text-success">{formatMoney(sale.margin, currency)}</dd>
          </div>
        </div>
      ) : null}
    </dl>
  );
}

function PaymentsPanel({
  sale,
  currency,
  onVoid,
}: {
  sale: Sale;
  currency: string;
  onVoid: (payment: SalePayment) => void;
}) {
  const payments = sale.payments ?? [];

  return (
    <section className="rounded-xl border bg-card p-6 shadow-card">
      <h2 className="text-lg">Paiements</h2>

      {payments.length === 0 ? (
        <p className="mt-4 text-sm text-muted-foreground">
          Aucun paiement enregistré.
          {sale.can.collect ? " Utilisez « Encaisser » quand le client paie." : ""}
        </p>
      ) : (
        <ul className="mt-2 divide-y">
          {payments.map((payment) => {
            const voided = payment.status === "voided";

            return (
              <li key={payment.id} className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-1">
                  <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-foreground">
                    {payment.method_label}
                    {voided ? <Badge variant="danger">Annulé</Badge> : null}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {formatDateTime(payment.paid_at)}
                    {payment.created_by ? ` · ${payment.created_by}` : ""}
                    {payment.reference ? ` · Réf. ${payment.reference}` : ""}
                  </p>
                  {payment.tendered !== null ? (
                    <p className="text-xs text-muted-foreground">
                      Reçu {formatMoney(payment.tendered, currency)}
                      {isPositive(payment.change_given) ? ` · Rendu ${formatMoney(payment.change_given, currency)}` : ""}
                    </p>
                  ) : null}
                  {voided && payment.void_reason ? (
                    <p className="text-xs text-danger">
                      {payment.void_reason}
                      {payment.voided_at ? ` (${formatDateTime(payment.voided_at)})` : ""}
                    </p>
                  ) : null}
                </div>

                <div className="flex items-center justify-between gap-4 sm:flex-col sm:items-end sm:justify-start sm:gap-2">
                  <p
                    className={
                      voided
                        ? "font-medium tabular-nums text-muted-foreground line-through"
                        : "font-medium tabular-nums text-foreground"
                    }
                  >
                    {formatMoney(payment.amount, currency)}
                  </p>

                  {sale.can.void_payment && !voided ? (
                    <Button variant="ghost" size="sm" onClick={() => onVoid(payment)}>
                      Annuler
                    </Button>
                  ) : null}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}

// ---------------------------------------------------------------- Dialogues

function CollectDialog({
  sale,
  open,
  onOpenChange,
}: {
  sale: Sale;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { tenant } = useActiveMembership();
  const currency = tenant.currency;
  const decimals = currencyDecimals(currency);
  const meta = useSaleMeta();
  const mutations = useSaleMutations();

  const [method, setMethod] = useState("cash");
  const [amount, setAmount] = useState(sale.balance_due);
  const [reference, setReference] = useState("");
  const [errors, setErrors] = useState<{ method?: string; amount?: string; reference?: string }>({});
  const [error, setError] = useState<string | null>(null);

  const methods = meta.data?.payment_methods ?? [];
  const info = methods.find((item) => item.value === method);
  const parsed = parseDecimal(amount);
  const balance = parseDecimal(sale.balance_due) ?? zero(decimals);
  const change = info?.is_cash && parsed && compare(parsed, balance) > 0 ? subtract(parsed, balance) : null;

  function reset() {
    setMethod("cash");
    setAmount(sale.balance_due);
    setReference("");
    setErrors({});
    setError(null);
  }

  async function confirm() {
    setErrors({});
    setError(null);

    try {
      await mutations.collect.mutateAsync({
        id: sale.id,
        input: {
          method,
          amount: normalizeNumber(amount),
          reference: info?.is_cash ? null : emptyToNull(reference),
        },
      });

      toast.success(
        change
          ? `Paiement enregistré. Rendez ${formatMoney(toMoney(change, decimals), currency)} au client.`
          : "Paiement enregistré.",
      );
      onOpenChange(false);
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

      setError(errorMessage(caught));
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => {
        if (!next && !mutations.collect.isPending) {
          reset();
          onOpenChange(false);
        }
      }}
      title="Encaisser un paiement"
      description={`Reste à payer sur ${sale.number} : ${formatMoney(sale.balance_due, currency)}.`}
      confirmLabel="Encaisser"
      pending={mutations.collect.isPending}
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

        <Field label={info?.is_cash ? "Montant reçu" : "Montant"} error={errors.amount}>
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
            Rendu de monnaie : <span className="font-semibold tabular-nums">{formatMoney(toMoney(change, decimals), currency)}</span>
          </p>
        ) : null}

        {info && !info.is_cash ? (
          <Field label="N° de transaction" optional error={errors.reference}>
            <Input
              size="lg"
              autoComplete="off"
              value={reference}
              onChange={(event) => setReference(event.target.value)}
            />
          </Field>
        ) : null}
      </div>
    </ConfirmDialog>
  );
}

function VoidPaymentDialog({
  sale,
  payment,
  onClose,
}: {
  sale: Sale;
  payment: SalePayment | null;
  onClose: () => void;
}) {
  const { tenant } = useActiveMembership();
  const mutations = useSaleMutations();
  const [reason, setReason] = useState("");
  const [fieldError, setFieldError] = useState<string | undefined>();
  const [error, setError] = useState<string | null>(null);

  function reset() {
    setReason("");
    setFieldError(undefined);
    setError(null);
  }

  async function confirm() {
    if (!payment) return;

    setFieldError(undefined);
    setError(null);

    try {
      await mutations.voidPayment.mutateAsync({ saleId: sale.id, paymentId: payment.id, reason });
      toast.success("Paiement annulé : le montant est de nouveau dû.");
      reset();
      onClose();
    } catch (caught) {
      if (caught instanceof ApiError && caught.code === "validation_error" && caught.fieldErrors.reason?.[0]) {
        setFieldError(caught.fieldErrors.reason[0]);
      } else {
        setError(errorMessage(caught));
      }
    }
  }

  return (
    <ConfirmDialog
      open={payment !== null}
      onOpenChange={(next) => {
        if (!next && !mutations.voidPayment.isPending) {
          reset();
          onClose();
        }
      }}
      title="Annuler ce paiement ?"
      description={
        payment
          ? `Le paiement ${payment.method_label} de ${formatMoney(payment.amount, tenant.currency)} sera annulé : ce montant redevient dû sur cette vente. Le paiement et son historique sont conservés.`
          : ""
      }
      confirmLabel="Annuler le paiement"
      destructive
      pending={mutations.voidPayment.isPending}
      onConfirm={() => void confirm()}
      error={error}
    >
      <Field label="Pourquoi annulez-vous ce paiement ?" error={fieldError}>
        <Textarea
          value={reason}
          placeholder="Paiement non reçu, erreur de caisse…"
          onChange={(event) => {
            setReason(event.target.value);
            setFieldError(undefined);
          }}
        />
      </Field>
    </ConfirmDialog>
  );
}

function CancelSaleDialog({
  sale,
  open,
  onOpenChange,
}: {
  sale: Sale;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { tenant } = useActiveMembership();
  const mutations = useSaleMutations();
  const [reason, setReason] = useState("");
  const [fieldError, setFieldError] = useState<string | undefined>();
  const [error, setError] = useState<string | null>(null);

  const paid = isPositive(sale.amount_paid);

  function reset() {
    setReason("");
    setFieldError(undefined);
    setError(null);
  }

  async function confirm() {
    setFieldError(undefined);
    setError(null);

    try {
      await mutations.cancel.mutateAsync({ id: sale.id, reason });
      toast.success("Vente annulée : les articles sont revenus en stock.");
      reset();
      onOpenChange(false);
    } catch (caught) {
      if (caught instanceof ApiError && caught.code === "validation_error" && caught.fieldErrors.reason?.[0]) {
        setFieldError(caught.fieldErrors.reason[0]);
      } else {
        setError(errorMessage(caught));
      }
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => {
        if (!next && !mutations.cancel.isPending) {
          reset();
          onOpenChange(false);
        }
      }}
      title="Annuler cette vente ?"
      description="Les articles retournent en stock dans les lots d'où ils ont été pris, et les paiements enregistrés sont annulés. La vente et son historique sont conservés."
      confirmLabel="Annuler la vente"
      destructive
      pending={mutations.cancel.isPending}
      onConfirm={() => void confirm()}
      error={error}
    >
      <div className="space-y-4">
        {paid ? (
          <Alert variant="warning" title="Pensez au remboursement">
            Ce client a payé {formatMoney(sale.amount_paid, tenant.currency)} : rendez-lui cette somme.
          </Alert>
        ) : null}

        <Field label="Pourquoi annulez-vous cette vente ?" error={fieldError}>
          <Textarea
            value={reason}
            placeholder="Erreur de saisie, retour du client…"
            onChange={(event) => {
              setReason(event.target.value);
              setFieldError(undefined);
            }}
          />
        </Field>
      </div>
    </ConfirmDialog>
  );
}

// ------------------------------------------------------------------- Fiche

export function SaleDetail({ id }: { id: string }) {
  const { tenant } = useActiveMembership();
  const query = useSale(id);

  const [collectOpen, setCollectOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [voidTarget, setVoidTarget] = useState<SalePayment | null>(null);

  if (query.isPending) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-28 w-full rounded-xl" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
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
      <div className="space-y-4">
        <Alert variant="danger" title="Impossible de charger la vente">
          {errorMessage(query.error)}
        </Alert>
        <Button variant="secondary" onClick={() => void query.refetch()}>
          Réessayer
        </Button>
      </div>
    );
  }

  const sale = query.data;
  const currency = tenant.currency;
  const lines = sale.lines ?? [];

  return (
    <div className="space-y-8">
      <Link
        href="/sales"
        className="inline-flex items-center gap-2 text-sm text-muted-foreground transition-colors duration-200 ease-smooth hover:text-foreground"
      >
        <ArrowLeft className="size-4" strokeWidth={1.5} aria-hidden />
        Ventes
      </Link>

      <header className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-2">
          <h1 className="text-3xl">{sale.number}</h1>
          <div className="flex flex-wrap items-center gap-2">
            <SaleStatusBadge sale={sale} />
            <span className="text-sm text-muted-foreground">{formatDate(sale.sale_date)}</span>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          {sale.can.collect ? (
            <Button onClick={() => setCollectOpen(true)}>
              <Banknote strokeWidth={1.5} aria-hidden />
              Encaisser
            </Button>
          ) : null}

                    <Link href={`/sales/${sale.id}/receipt`} className={buttonVariants({ variant: "secondary" })}>
            <span className="inline-flex items-center gap-2">
              <Printer className="size-4" strokeWidth={1.5} aria-hidden />
              Reçu
            </span>
          </Link>

          {sale.can.cancel ? (
            <Button variant="secondary" onClick={() => setCancelOpen(true)}>
              <Undo2 strokeWidth={1.5} aria-hidden />
              Annuler la vente
            </Button>
          ) : null}
        </div>
      </header>

      {sale.status === "cancelled" ? (
        <Alert variant="danger" title="Vente annulée">
          Annulée {sale.cancelled_at ? `le ${formatDateTime(sale.cancelled_at)}` : ""}
          {sale.cancelled_by ? ` par ${sale.cancelled_by}` : ""}. Motif : {sale.cancel_reason}. Les articles sont
          revenus en stock et les paiements ont été annulés.
        </Alert>
      ) : null}

      {sale.status === "validated" && sale.can.collect ? (
        <Alert variant="warning" title="Reste à payer">
          {formatMoney(sale.balance_due, currency)} restent dus
          {sale.customer ? ` par ${sale.customer.name}` : ""}.
        </Alert>
      ) : null}

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Vente</h2>

        <dl className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <Detail label="Client">
            {sale.customer ? (
              <>
                {sale.customer.name}
                {sale.customer.phone ? (
                  <span className="block text-xs font-normal text-muted-foreground">{sale.customer.phone}</span>
                ) : null}
              </>
            ) : (
              "Client de passage"
            )}
          </Detail>
          <Detail label="Entrepôt">{sale.warehouse.name}</Detail>
          <Detail label="Enregistrée par">
            {sale.created_by ?? "—"}
            {sale.created_at ? (
              <span className="block text-xs font-normal text-muted-foreground">{formatDateTime(sale.created_at)}</span>
            ) : null}
          </Detail>
        </dl>

        {sale.notes ? <p className="mt-6 border-t pt-5 text-sm text-muted-foreground">{sale.notes}</p> : null}
      </section>

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Articles vendus</h2>

        <div className="mt-5">
          <LinesTable lines={lines} currency={currency} />
          <LinesCards lines={lines} currency={currency} />
        </div>

        <div className="mt-5 border-t pt-5">
          <Totals sale={sale} currency={currency} />
        </div>
      </section>

      <PaymentsPanel sale={sale} currency={currency} onVoid={setVoidTarget} />

      <CollectDialog
        key={`${sale.amount_paid}:${sale.payments?.length ?? 0}`}
        sale={sale}
        open={collectOpen && sale.can.collect}
        onOpenChange={setCollectOpen}
      />

      <VoidPaymentDialog sale={sale} payment={voidTarget} onClose={() => setVoidTarget(null)} />

      <CancelSaleDialog sale={sale} open={cancelOpen && sale.can.cancel} onOpenChange={setCancelOpen} />
    </div>
  );
}