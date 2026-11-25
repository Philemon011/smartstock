"use client";

import { Banknote, CreditCard, Landmark, Plus, Smartphone, Trash2, type LucideIcon } from "lucide-react";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";
import { CustomerSheet } from "@/components/customers/customer-sheet";
import {
  SaleLineEditor,
  type SaleLineErrors,
  type SaleLineField,
} from "@/components/sales/sale-line-editor";
import { useActiveMembership } from "@/components/session/session-provider";
import { MICRO_ZERO, toMicro } from "@/components/stock-exits/exit-line-editor";
import { AffixInput } from "@/components/ui/affix-input";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { FormSection } from "@/components/ui/form-section";
import { Input } from "@/components/ui/input";
import { RemoteCombobox } from "@/components/ui/remote-combobox";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useSaleMeta, useSaleMutations } from "@/hooks/use-sales";
import { useWarehouses } from "@/hooks/use-warehouses";
import { ApiError } from "@/lib/api/client";
import { listCustomers, type Customer } from "@/lib/api/customers";
import type { PaymentMethod, Sale, SaleInput } from "@/lib/api/sales";
import { add, decimalToString, parseDecimal } from "@/lib/decimal";
import { currencyDecimals, formatMoney, todayIn } from "@/lib/format";
import { pickFieldMessages } from "@/lib/forms";
import {
  allocatePayments,
  compare,
  computeSaleLine,
  emptySaleRow,
  normalizeNumber,
  subtract,
  toMoney,
  zero,
  type SaleLineRow,
} from "@/lib/sale-math";
import { useActiveMembership as useMembership } from "@/components/session/session-provider";

const HEADER_FIELDS = ["sale_date", "warehouse_id", "customer_id", "notes"] as const;

type HeaderField = (typeof HEADER_FIELDS)[number];
type HeaderErrors = Partial<Record<HeaderField, string>>;
type PaymentField = "method" | "amount" | "reference";
type PaymentErrors = Partial<Record<PaymentField, string>>;

type PaymentRow = { key: string; method: PaymentMethod; amount: string; reference: string };

const METHOD_ICONS: Record<PaymentMethod, LucideIcon> = {
  cash: Banknote,
  mtn_momo: Smartphone,
  moov_money: Smartphone,
  card: CreditCard,
  bank_transfer: Landmark,
};

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

type SaleFormProps = {
  warehouseId: string | null;
  onWarehouseChange: (id: string) => void;
  onCompleted: (sale: Sale) => void;
};

export function SaleForm({ warehouseId, onWarehouseChange, onCompleted }: SaleFormProps) {
  const { tenant } = useActiveMembership();
  const decimals = currencyDecimals(tenant.currency);
  const currency = tenant.currency;

  const meta = useSaleMeta();
  const warehouses = useWarehouses({ search: "", status: "all" });
  const mutations = useSaleMutations();

  const [saleDate, setSaleDate] = useState(todayIn(tenant.timezone));
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [notes, setNotes] = useState("");
  const [rows, setRows] = useState<SaleLineRow[]>([emptySaleRow()]);
  const [paymentRows, setPaymentRows] = useState<PaymentRow[]>([]);

  const [headerErrors, setHeaderErrors] = useState<HeaderErrors>({});
  const [lineErrors, setLineErrors] = useState<Record<number, SaleLineErrors>>({});
  const [paymentErrors, setPaymentErrors] = useState<Record<number, PaymentErrors>>({});
  const [linesError, setLinesError] = useState<string | undefined>();
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [customerSheetOpen, setCustomerSheetOpen] = useState(false);

  if (meta.isPending || warehouses.isPending) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-64 w-full rounded-xl" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }

  if (meta.isError || warehouses.isError) {
    return (
      <Alert variant="danger" title="Impossible de préparer la caisse">
        Impossible de charger les modes de paiement ou les entrepôts. Rechargez la page, puis réessayez.
      </Alert>
    );
  }

  const methods = meta.data.payment_methods;
  const methodInfo = (method: PaymentMethod) =>
    methods.find((item) => item.value === method) ?? { value: method, label: method, is_cash: false };

  const warehouseList = warehouses.data.data;
  const mainWarehouse = warehouseList.find((item) => item.is_main && item.is_active);
  const effectiveWarehouseId = warehouseId ?? mainWarehouse?.id ?? "";

  const warehouseOptions = warehouseList
    .filter((item) => item.is_active || item.id === effectiveWarehouseId)
    .map((item) => ({ value: item.id, label: item.is_active ? item.name : `${item.name} (désactivé)` }));

  // ------------------------------------------------------------------ Calculs

  const computedRows = rows.map((row) => computeSaleLine(row, decimals));

  let subtotal = zero(decimals);
  let discountTotal = zero(decimals);
  let total = zero(decimals);

  for (const line of computedRows) {
    if (line.gross && line.discount && line.total) {
      subtotal = add(subtotal, line.gross);
      discountTotal = add(discountTotal, line.discount);
      total = add(total, line.total);
    }
  }

  const allocation = allocatePayments(
    total,
    paymentRows.map((row) => ({ isCash: methodInfo(row.method).is_cash, amount: row.amount })),
    decimals,
  );

  const hasTotal = compare(total, zero(decimals)) > 0;
  const hasRemaining = hasTotal && compare(allocation.remaining, zero(decimals)) > 0;
  const hasChange = compare(allocation.change, zero(decimals)) > 0;
  const hasDiscount = compare(discountTotal, zero(decimals)) > 0;

  const customerDue = parseDecimal(customer?.balance_due ?? "0") ?? zero(decimals);
  const customerLimit = customer?.credit_limit != null ? parseDecimal(customer.credit_limit) : null;
  const creditAfter = add(customerDue, hasRemaining ? allocation.remaining : zero(decimals));
  const overLimit = customer !== null && customerLimit !== null && hasRemaining && compare(creditAfter, customerLimit) > 0;

  // ------------------------------------------------------------------ Actions

    /** Le stock dépend de l'entrepôt : changer d'entrepôt retire les articles déjà choisis. */
  function changeWarehouse(value: string) {
    if (value === effectiveWarehouseId) return;

    onWarehouseChange(value);

    if (rows.some((row) => row.article !== null)) {
      setRows((current) =>
        current.map((row) => (row.article === null ? row : { ...emptySaleRow(), key: row.key })),
      );
      setLineErrors({});
      setLinesError(undefined);
      toast.info("Les articles ont été retirés des lignes : le stock dépend de l'entrepôt choisi.");
    }

    clearHeaderError("warehouse_id");
  }

  function patchRow(key: string, patch: Partial<SaleLineRow>) {
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    setLineErrors({});
    setLinesError(undefined);
  }

  function clearHeaderError(field: HeaderField) {
    setHeaderErrors((previous) => ({ ...previous, [field]: undefined }));
  }

  function patchPayment(key: string, patch: Partial<PaymentRow>) {
    setPaymentRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    setPaymentErrors({});
  }

  function addPayment(method: PaymentMethod) {
    const suggestion = hasRemaining ? toMoney(allocation.remaining, decimals) : "";

    setPaymentRows((current) => [
      ...current,
      { key: crypto.randomUUID(), method, amount: suggestion === "0" ? "" : suggestion, reference: "" },
    ]);
    setPaymentErrors({});
  }

  function requestedBefore(index: number): bigint {
    const current = rows[index];

    if (!current.article) return MICRO_ZERO;

    let sum = MICRO_ZERO;

    for (let position = 0; position < index; position += 1) {
      const other = rows[position];
      const quantity = computedRows[position].stockQuantity;

      if (other.article?.id === current.article.id) {
        sum += toMicro(quantity ? decimalToString(quantity, true) : null) ?? MICRO_ZERO;
      }
    }

    return sum;
  }

  function applyError(error: unknown) {
    if (error instanceof ApiError && error.code === "validation_error") {
      const header = pickFieldMessages(error.fieldErrors, HEADER_FIELDS);
      const lines: Record<number, SaleLineErrors> = {};
      const payments: Record<number, PaymentErrors> = {};

      for (const [key, list] of Object.entries(error.fieldErrors)) {
        const line = /^lines\.(\d+)\.(\w+)$/.exec(key);
        const payment = /^payments\.(\d+)\.(\w+)$/.exec(key);

        if (line && list[0]) {
          const index = Number(line[1]);

          lines[index] = { ...lines[index], [line[2] as SaleLineField]: list[0] };
        }

        if (payment && list[0]) {
          const index = Number(payment[1]);

          payments[index] = { ...payments[index], [payment[2] as PaymentField]: list[0] };
        }
      }

      const general = error.fieldErrors.lines?.[0];

      if (
        Object.keys(header).length > 0 ||
        Object.keys(lines).length > 0 ||
        Object.keys(payments).length > 0 ||
        general
      ) {
        setHeaderErrors(header);
        setLineErrors(lines);
        setPaymentErrors(payments);
        setLinesError(general);
        window.setTimeout(() => {
          document.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
        }, 0);
        return;
      }
    }

    setFormError(
      error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.",
    );
  }

  async function submit() {
    if (submitting) return;

    setSubmitting(true);
    setFormError(null);
    setHeaderErrors({});
    setLineErrors({});
    setPaymentErrors({});
    setLinesError(undefined);

    const input: SaleInput = {
      sale_date: saleDate,
      warehouse_id: effectiveWarehouseId,
      customer_id: customer?.id ?? null,
      notes: emptyToNull(notes),
      lines: rows.map((row) => ({
        article_id: row.article?.id ?? "",
        packaging_id: row.packagingId,
        entered_quantity: normalizeNumber(row.quantity),
        unit_price: row.unitPrice.trim() === "" ? null : normalizeNumber(row.unitPrice),
        discount_type: row.discountType === "none" ? null : row.discountType,
        discount_value:
          row.discountType === "none" || row.discountValue.trim() === ""
            ? null
            : normalizeNumber(row.discountValue),
      })),
      payments: paymentRows
        .filter((row) => row.amount.trim() !== "")
        .map((row) => ({
          method: row.method,
          amount: normalizeNumber(row.amount),
          reference: emptyToNull(row.reference),
        })),
    };

    try {
      const sale = await mutations.create.mutateAsync(input);

      toast.success(`Vente ${sale.number} enregistrée.`);
      onCompleted(sale);
    } catch (error) {
      applyError(error);
      setSubmitting(false);
    }
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void submit();
  }

  const money = (value: ReturnType<typeof zero>) => formatMoney(toMoney(value, decimals), currency);

  return (
    <>
      <form
        noValidate
        onSubmit={handleSubmit}
        aria-busy={submitting || undefined}
        className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem] lg:items-start"
      >
        <div className="space-y-6">
          <FormSection title="Vente">
            <div className="grid gap-5 sm:grid-cols-2">
              <Field label="Entrepôt d'origine" error={headerErrors.warehouse_id}>
                <Select
                  size="lg"
                  options={warehouseOptions}
                  value={effectiveWarehouseId}
                                    onValueChange={changeWarehouse}
                  placeholder="Choisir un entrepôt"
                />
              </Field>

              <Field label="Date de la vente" error={headerErrors.sale_date}>
                <Input
                  size="lg"
                  type="date"
                  value={saleDate}
                  max={todayIn(tenant.timezone)}
                  onChange={(event) => {
                    setSaleDate(event.target.value);
                    clearHeaderError("sale_date");
                  }}
                />
              </Field>
            </div>
          </FormSection>

          <FormSection
            title="Articles vendus"
            description="Le prix du catalogue est pré-rempli : vous pouvez le modifier ou accorder une remise sur chaque ligne."
          >
            <ul className="space-y-4">
              {rows.map((row, index) => (
                <SaleLineEditor
                  key={row.key}
                  row={row}
                  index={index}
                  errors={lineErrors[index] ?? {}}
                  computed={computedRows[index]}
                  currency={currency}
                  decimals={decimals}
                  warehouseId={effectiveWarehouseId}
                  alreadyRequested={requestedBefore(index)}
                  onChange={(patch) => patchRow(row.key, patch)}
                  onRemove={() => setRows((current) => current.filter((item) => item.key !== row.key))}
                  removable={rows.length > 1}
                />
              ))}
            </ul>

            {linesError ? <p className="text-sm text-danger">{linesError}</p> : null}

            <Button
              type="button"
              variant="secondary"
              size="md"
              disabled={rows.length >= 100}
              onClick={() => setRows((current) => [...current, emptySaleRow()])}
            >
              <Plus strokeWidth={1.5} aria-hidden />
              Ajouter une ligne
            </Button>
          </FormSection>

          <FormSection title="Notes">
            <Field label="Notes" optional error={headerErrors.notes}>
              <Textarea
                placeholder="Remarques sur la vente…"
                value={notes}
                onChange={(event) => {
                  setNotes(event.target.value);
                  clearHeaderError("notes");
                }}
              />
            </Field>
          </FormSection>
        </div>

        <aside className="space-y-6 lg:sticky lg:top-6">
          <FormSection title="Client">
            <Field
              label="Client"
              optional
              error={headerErrors.customer_id}
              hint="Obligatoire dès qu'il reste un montant à payer."
            >
              <RemoteCombobox<Customer>
                size="lg"
                queryKey={["customers", tenant.id, "picker"]}
                fetcher={(term, signal) =>
                  listCustomers({ search: term, status: "active", page: 1 }, signal).then((result) => result.data)
                }
                value={customer}
                getKey={(item) => item.id}
                renderItem={(item) => (
                  <span className="flex min-w-0 flex-col text-left">
                    <span className="truncate">{item.name}</span>
                    {item.phone ? (
                      <span className="truncate text-xs text-muted-foreground">{item.phone}</span>
                    ) : null}
                  </span>
                )}
                onChange={(item) => {
                  setCustomer(item);
                  clearHeaderError("customer_id");
                }}
                onClear={() => setCustomer(null)}
                placeholder="Client de passage"
                searchPlaceholder="Nom, téléphone, email ou ville"
                emptyMessage="Aucun client ne correspond."
                footer={(close) => (
                  <button
                    type="button"
                    onClick={() => {
                      close();
                      setCustomerSheetOpen(true);
                    }}
                    className="flex h-10 w-full items-center gap-2 rounded-sm px-3 text-sm font-medium text-primary outline-none transition-colors duration-150 hover:bg-accent focus-visible:bg-accent"
                  >
                    <Plus className="size-4" strokeWidth={1.5} aria-hidden />
                    Créer un nouveau client
                  </button>
                )}
              />
            </Field>

            {customer ? (
              <dl className="space-y-1.5 rounded-lg bg-accent/60 p-3 text-sm">
                <div className="flex justify-between gap-3">
                  <dt className="text-muted-foreground">Doit déjà</dt>
                  <dd className="font-medium tabular-nums text-foreground">{money(customerDue)}</dd>
                </div>
                <div className="flex justify-between gap-3">
                  <dt className="text-muted-foreground">Plafond de crédit</dt>
                  <dd className="font-medium tabular-nums text-foreground">
                    {customerLimit === null
                      ? "Sans plafond"
                      : customerLimit.value === BigInt(0)
                        ? "Aucun crédit"
                        : money(customerLimit)}
                  </dd>
                </div>
                {customerLimit !== null && customerLimit.value > BigInt(0) ? (
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">Crédit disponible</dt>
                    <dd className="font-medium tabular-nums text-foreground">
                      {compare(customerLimit, customerDue) > 0 ? money(subtract(customerLimit, customerDue)) : money(zero(decimals))}
                    </dd>
                  </div>
                ) : null}
              </dl>
            ) : null}

            {overLimit ? (
              <p className="text-sm text-danger">
                Cette vente à crédit dépasserait le plafond de ce client : encaissez davantage ou réduisez la vente.
              </p>
            ) : null}
          </FormSection>

          <FormSection title="Paiement">
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between gap-3">
                <dt className="text-muted-foreground">Sous-total</dt>
                <dd className="tabular-nums text-foreground">{money(subtotal)}</dd>
              </div>
              {hasDiscount ? (
                <div className="flex justify-between gap-3">
                  <dt className="text-muted-foreground">Remises</dt>
                  <dd className="tabular-nums text-foreground">− {money(discountTotal)}</dd>
                </div>
              ) : null}
              <div className="flex items-end justify-between gap-3 border-t pt-3">
                <dt className="text-muted-foreground">Total à payer</dt>
                <dd className="text-2xl font-semibold tabular-nums tracking-display text-foreground">
                  {money(total)}
                </dd>
              </div>
            </dl>

            {paymentRows.length > 0 ? (
              <ul className="space-y-3">
                {paymentRows.map((row, index) => {
                  const info = methodInfo(row.method);
                  const Icon = METHOD_ICONS[row.method];
                  const result = allocation.rows[index];
                  const serverErrors = paymentErrors[index] ?? {};

                  const amountError =
                    serverErrors.amount ??
                    (result.status === "exceeds"
                      ? `Ce paiement dépasse le montant restant : seules les espèces donnent lieu à un rendu de monnaie.`
                      : result.status === "settled"
                        ? "La vente est déjà entièrement payée : retirez ce paiement."
                        : undefined);

                  return (
                    <li key={row.key} className="rounded-lg border bg-background p-3">
                      <div className="flex items-center justify-between gap-2">
                        <p className="flex items-center gap-2 text-sm font-medium text-foreground">
                          <Icon className="size-4 text-muted-foreground" strokeWidth={1.5} aria-hidden />
                          {info.label}
                        </p>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="size-8 px-0"
                          aria-label={`Retirer le paiement ${info.label}`}
                          onClick={() =>
                            setPaymentRows((current) => current.filter((item) => item.key !== row.key))
                          }
                        >
                          <Trash2 className="size-4" strokeWidth={1.5} aria-hidden />
                        </Button>
                      </div>

                      <div className="mt-3 space-y-3">
                        <Field label={info.is_cash ? "Montant reçu" : "Montant"} error={amountError}>
                          <AffixInput
                            size="lg"
                            inputMode="decimal"
                            autoComplete="off"
                            placeholder="0"
                            suffix={currency}
                            value={row.amount}
                            onChange={(event) => patchPayment(row.key, { amount: event.target.value })}
                          />
                        </Field>

                        {info.is_cash ? (
                          compare(result.change, zero(decimals)) > 0 ? (
                            <p className="rounded-md bg-success-soft px-3 py-2 text-sm text-success">
                              Rendu de monnaie :{" "}
                              <span className="font-semibold tabular-nums">{money(result.change)}</span>
                            </p>
                          ) : null
                        ) : (
                          <Field label="N° de transaction" optional error={serverErrors.reference}>
                            <Input
                              size="lg"
                              autoComplete="off"
                              value={row.reference}
                              onChange={(event) => patchPayment(row.key, { reference: event.target.value })}
                            />
                          </Field>
                        )}
                      </div>
                    </li>
                  );
                })}
              </ul>
            ) : null}

            {hasTotal && (paymentRows.length === 0 || hasRemaining) ? (
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">
                  {paymentRows.length === 0 ? "Encaisser avec" : "Ajouter un paiement"}
                </p>
                <div className="flex flex-wrap gap-2">
                  {methods.map((item) => {
                    const Icon = METHOD_ICONS[item.value];

                    return (
                      <Button
                        key={item.value}
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={() => addPayment(item.value)}
                      >
                        <Icon strokeWidth={1.5} aria-hidden />
                        {item.label}
                      </Button>
                    );
                  })}
                </div>
              </div>
            ) : null}

            {hasTotal ? (
              <dl className="space-y-2 border-t pt-4 text-sm">
                <div className="flex justify-between gap-3">
                  <dt className="text-muted-foreground">Payé</dt>
                  <dd className="font-medium tabular-nums text-foreground">{money(allocation.paid)}</dd>
                </div>
                <div className="flex justify-between gap-3">
                  <dt className="text-muted-foreground">
                    {hasRemaining ? "Reste à payer (à crédit)" : "Reste à payer"}
                  </dt>
                  <dd
                    className={
                      hasRemaining
                        ? "font-semibold tabular-nums text-warning"
                        : "font-medium tabular-nums text-foreground"
                    }
                  >
                    {money(allocation.remaining)}
                  </dd>
                </div>
                {hasChange ? (
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">Rendu de monnaie</dt>
                    <dd className="font-semibold tabular-nums text-success">{money(allocation.change)}</dd>
                  </div>
                ) : null}
              </dl>
            ) : null}

            {hasRemaining && !customer ? (
              <p className="text-sm text-warning">
                Il reste un montant à payer : choisissez un client pour enregistrer la vente à crédit.
              </p>
            ) : null}

            {formError ? (
              <Alert variant="danger" title="Vente impossible">
                {formError}
              </Alert>
            ) : null}

            <Button type="submit" size="lg" className="w-full" loading={submitting}>
              Valider la vente{hasTotal ? ` · ${money(total)}` : ""}
            </Button>
          </FormSection>
        </aside>
      </form>

      <CustomerSheet
        open={customerSheetOpen}
        onOpenChange={setCustomerSheetOpen}
        customer={null}
        onCreated={(created) => {
          setCustomer(created);
          clearHeaderError("customer_id");
        }}
      />
    </>
  );
}