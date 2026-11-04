"use client";

import { Plus } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";
import {
  EntryLineEditor,
  computeLine,
  emptyRow,
  type LineErrors,
  type LineField,
  type LineRow,
} from "@/components/stock-entries/entry-line-editor";
import { QuickArticleSheet } from "@/components/stock-entries/quick-article-sheet";
import { QuickSupplierSheet } from "@/components/stock-entries/quick-supplier-sheet";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { FormSection } from "@/components/ui/form-section";
import { Input } from "@/components/ui/input";
import { RemoteCombobox } from "@/components/ui/remote-combobox";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useStockEntryMutations, useStockMeta } from "@/hooks/use-stock-entries";
import { useWarehouses } from "@/hooks/use-warehouses";
import type { Article } from "@/lib/api/articles";
import { ApiError } from "@/lib/api/client";
import { add, decimalToString, ZERO } from "@/lib/decimal";
import type { StockEntry, StockEntryInput } from "@/lib/api/stock-entries";
import { listSuppliers, type Supplier } from "@/lib/api/suppliers";
import { formatMoney, todayIn } from "@/lib/format";
import { pickFieldMessages } from "@/lib/forms";

const HEADER_FIELDS = [
  "reason",
  "entry_date",
  "warehouse_id",
  "supplier_id",
  "reference",
  "notes",
] as const;

type HeaderField = (typeof HEADER_FIELDS)[number];
type HeaderErrors = Partial<Record<HeaderField, string>>;

type SupplierChoice = { id: string; name: string };

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

type EntryFormProps = {
  entry: StockEntry | null;
  initialArticles: Map<string, Article>;
};

export function EntryForm({ entry, initialArticles }: EntryFormProps) {
  const router = useRouter();
  const { tenant } = useActiveMembership();
  const mutations = useStockEntryMutations();
  const meta = useStockMeta();
  const warehouses = useWarehouses({ search: "", status: "all" });

  const [reason, setReason] = useState<string>(entry?.reason ?? "purchase");
  const [entryDate, setEntryDate] = useState(entry?.entry_date ?? todayIn(tenant.timezone));
  const [warehouseId, setWarehouseId] = useState<string | null>(entry?.warehouse.id ?? null);
  const [supplier, setSupplier] = useState<SupplierChoice | null>(entry?.supplier ?? null);
  const [reference, setReference] = useState(entry?.reference ?? "");
  const [notes, setNotes] = useState(entry?.notes ?? "");
  const [rows, setRows] = useState<LineRow[]>(() =>
    entry?.lines && entry.lines.length > 0
      ? entry.lines.map((line) => ({
          key: line.id,
          article: initialArticles.get(line.article.id) ?? null,
          packagingId: line.packaging_id,
          quantity: line.entered_quantity,
          unitPrice: line.unit_price,
          expiryDate: line.expiry_date ?? "",
          lotNumber: line.lot_number ?? "",
          salePrice: line.sale_price ?? "",
        }))
      : [emptyRow()],
  );

  const [headerErrors, setHeaderErrors] = useState<HeaderErrors>({});
  const [lineErrors, setLineErrors] = useState<Record<number, LineErrors>>({});
  const [linesError, setLinesError] = useState<string | undefined>();
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const [supplierSheetOpen, setSupplierSheetOpen] = useState(false);
  const [articleSheetFor, setArticleSheetFor] = useState<string | null>(null);

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
      <Alert variant="danger" title="Impossible de préparer le formulaire">
        Impossible de charger les motifs ou les entrepôts. Rechargez la page, puis réessayez.
      </Alert>
    );
  }

  const reasons = meta.data.entry_reasons;
  const currentReason = reasons.find((item) => item.value === reason);
  const warehouseList = warehouses.data.data;
  const mainWarehouse = warehouseList.find((item) => item.is_main && item.is_active);
  const effectiveWarehouseId = warehouseId ?? mainWarehouse?.id ?? "";

  const warehouseOptions = warehouseList
    .filter((item) => item.is_active || item.id === effectiveWarehouseId)
    .map((item) => ({ value: item.id, label: item.is_active ? item.name : `${item.name} (désactivé)` }));

  const total = rows.reduce(
    (sum, row) => {
      const { total: lineTotal } = computeLine(row, tenant.currency);

      return lineTotal ? add(sum, lineTotal) : sum;
    },
    ZERO,
  );

  function patchRow(key: string, patch: Partial<LineRow>) {
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    setLineErrors({});
    setLinesError(undefined);
  }

  function clearHeaderError(field: HeaderField) {
    setHeaderErrors((previous) => ({ ...previous, [field]: undefined }));
  }

  function applyError(error: unknown) {
    if (error instanceof ApiError && error.code === "validation_error") {
      const header = pickFieldMessages(error.fieldErrors, HEADER_FIELDS);
      const lines: Record<number, LineErrors> = {};

      for (const [key, list] of Object.entries(error.fieldErrors)) {
        const match = /^lines\.(\d+)\.(\w+)$/.exec(key);

        if (match && list[0]) {
          const index = Number(match[1]);

          lines[index] = { ...lines[index], [match[2] as LineField]: list[0] };
        }
      }

      const general = error.fieldErrors.lines?.[0];

      if (Object.keys(header).length > 0 || Object.keys(lines).length > 0 || general) {
        setHeaderErrors(header);
        setLineErrors(lines);
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

  async function submit(goToValidation: boolean) {
    if (submitting) return;

    setSubmitting(true);
    setFormError(null);
    setHeaderErrors({});
    setLineErrors({});
    setLinesError(undefined);

    const input: StockEntryInput = {
      reason,
      entry_date: entryDate,
      warehouse_id: effectiveWarehouseId,
      supplier_id: supplier?.id ?? null,
      reference: emptyToNull(reference),
      notes: emptyToNull(notes),
      lines: rows.map((row) => ({
        article_id: row.article?.id ?? "",
        packaging_id: row.packagingId,
        entered_quantity: row.quantity,
        unit_price: row.unitPrice,
        expiry_date: emptyToNull(row.expiryDate),
        lot_number: emptyToNull(row.lotNumber),
        sale_price: emptyToNull(row.salePrice),
      })),
    };

    try {
      const saved = entry
        ? await mutations.update.mutateAsync({ id: entry.id, input })
        : await mutations.create.mutateAsync(input);

      toast.success("Brouillon enregistré.");
      router.push(`/stock-entries/${saved.id}${goToValidation ? "?validate=1" : ""}`);
    } catch (error) {
      applyError(error);
      setSubmitting(false);
    }
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void submit(false);
  }

  return (
    <>
      <form noValidate onSubmit={handleSubmit} aria-busy={submitting || undefined} className="space-y-6">
        <FormSection title="Livraison">
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Motif" error={headerErrors.reason}>
              <Select
                size="lg"
                options={reasons.map((item) => ({ value: item.value, label: item.label }))}
                value={reason}
                onValueChange={(value) => {
                  setReason(value);
                  clearHeaderError("reason");
                }}
              />
            </Field>

            <Field label="Date de l'entrée" error={headerErrors.entry_date}>
              <Input
                size="lg"
                type="date"
                value={entryDate}
                max={todayIn(tenant.timezone)}
                onChange={(event) => {
                  setEntryDate(event.target.value);
                  clearHeaderError("entry_date");
                }}
              />
            </Field>

            <Field label="Entrepôt de destination" error={headerErrors.warehouse_id}>
              <Select
                size="lg"
                options={warehouseOptions}
                value={effectiveWarehouseId}
                onValueChange={(value) => {
                  setWarehouseId(value);
                  clearHeaderError("warehouse_id");
                }}
                placeholder="Choisir un entrepôt"
              />
            </Field>

            <Field
              label="Fournisseur"
              optional={!currentReason?.requires_supplier}
              error={headerErrors.supplier_id}
            >
              <RemoteCombobox<SupplierChoice>
                size="lg"
                queryKey={["entry-suppliers"]}
                fetcher={(term, signal) =>
                  listSuppliers({ search: term, status: "active", page: 1 }, signal).then((result) =>
                    result.data.map((item) => ({ id: item.id, name: item.name })),
                  )
                }
                value={supplier}
                getKey={(item) => item.id}
                renderItem={(item) => <span className="truncate">{item.name}</span>}
                onChange={(item) => {
                  setSupplier(item);
                  clearHeaderError("supplier_id");
                }}
                onClear={() => setSupplier(null)}
                placeholder="Rechercher un fournisseur"
                searchPlaceholder="Nom, contact, téléphone ou ville"
                emptyMessage="Aucun fournisseur ne correspond."
                footer={(close) => (
                  <button
                    type="button"
                    onClick={() => {
                      close();
                      setSupplierSheetOpen(true);
                    }}
                    className="flex h-10 w-full items-center gap-2 rounded-sm px-3 text-sm font-medium text-primary outline-none transition-colors duration-150 hover:bg-accent focus-visible:bg-accent"
                  >
                    <Plus className="size-4" strokeWidth={1.5} aria-hidden />
                    Créer un nouveau fournisseur
                  </button>
                )}
              />
            </Field>

            <Field
              label="Numéro du bon de livraison"
              optional
              error={headerErrors.reference}
              hint="Celui imprimé sur le bon ou la facture du fournisseur."
            >
              <Input
                size="lg"
                autoComplete="off"
                placeholder="BL-2041"
                value={reference}
                onChange={(event) => {
                  setReference(event.target.value);
                  clearHeaderError("reference");
                }}
              />
            </Field>
          </div>

          <Field label="Notes" optional error={headerErrors.notes}>
            <Textarea
              placeholder="Conditions de livraison, remarques…"
              value={notes}
              onChange={(event) => {
                setNotes(event.target.value);
                clearHeaderError("notes");
              }}
            />
          </Field>
        </FormSection>

        <FormSection
          title="Articles reçus"
          description={
            currentReason?.allows_zero_price
              ? "Pour un don, le prix d'achat peut être à zéro."
              : "Le prix d'achat et la date d'expiration sont propres à cette livraison : ils créent un lot."
          }
        >
          <ul className="space-y-4">
            {rows.map((row, index) => (
              <EntryLineEditor
                key={row.key}
                row={row}
                index={index}
                errors={lineErrors[index] ?? {}}
                currency={tenant.currency}
                onChange={(patch) => patchRow(row.key, patch)}
                onRemove={() => setRows((current) => current.filter((item) => item.key !== row.key))}
                removable={rows.length > 1}
                onCreateArticle={() => setArticleSheetFor(row.key)}
              />
            ))}
          </ul>

          {linesError ? <p className="text-sm text-danger">{linesError}</p> : null}

          <Button
            type="button"
            variant="secondary"
            size="md"
            disabled={rows.length >= 100}
            onClick={() => setRows((current) => [...current, emptyRow()])}
          >
            <Plus strokeWidth={1.5} aria-hidden />
            Ajouter une ligne
          </Button>

          <div className="flex items-center justify-between gap-4 border-t pt-5">
            <p className="text-sm text-muted-foreground">
              {rows.length} {rows.length > 1 ? "lignes" : "ligne"}
            </p>
            <p className="text-right text-sm text-muted-foreground">
              Total de l&apos;entrée
              <span className="block text-2xl font-semibold tabular-nums text-foreground">
                {formatMoney(decimalToString(total), tenant.currency)}
              </span>
            </p>
          </div>
        </FormSection>

        {formError ? (
          <Alert variant="danger" title="Enregistrement impossible">
            {formError}
          </Alert>
        ) : null}

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="ghost"
            size="lg"
            disabled={submitting}
            onClick={() => router.push(entry ? `/stock-entries/${entry.id}` : "/stock-entries")}
          >
            Annuler
          </Button>
          <Button type="submit" variant="secondary" size="lg" loading={submitting}>
            Enregistrer le brouillon
          </Button>
          <Button type="button" size="lg" disabled={submitting} onClick={() => void submit(true)}>
            Enregistrer et valider
          </Button>
        </div>
      </form>

      <QuickSupplierSheet
        open={supplierSheetOpen}
        onOpenChange={setSupplierSheetOpen}
        onCreated={(created: Supplier) => {
          setSupplier({ id: created.id, name: created.name });
          clearHeaderError("supplier_id");
        }}
      />

      <QuickArticleSheet
        open={articleSheetFor !== null}
        onOpenChange={(open) => {
          if (!open) setArticleSheetFor(null);
        }}
        onCreated={(created) => {
          if (articleSheetFor) {
            patchRow(articleSheetFor, { article: created, packagingId: null, expiryDate: "" });
          }
        }}
      />
    </>
  );
}