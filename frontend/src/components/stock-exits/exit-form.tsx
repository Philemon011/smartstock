"use client";

import { Plus } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";
import { useActiveMembership } from "@/components/session/session-provider";
import { QuickSupplierSheet } from "@/components/stock-entries/quick-supplier-sheet";
import {
  ExitLineEditor,
  MICRO_ZERO,
  convertedQuantity,
  emptyExitRow,
  toMicro,
  type ExitLineErrors,
  type ExitLineField,
  type ExitLineRow,
} from "@/components/stock-exits/exit-line-editor";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { FormSection } from "@/components/ui/form-section";
import { Input } from "@/components/ui/input";
import { RemoteCombobox } from "@/components/ui/remote-combobox";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useStockMeta } from "@/hooks/use-stock-entries";
import { useStockExitMutations } from "@/hooks/use-stock-exits";
import { useWarehouses } from "@/hooks/use-warehouses";
import type { Article } from "@/lib/api/articles";
import { ApiError } from "@/lib/api/client";
import type { StockExit, StockExitInput } from "@/lib/api/stock-exits";
import { listSuppliers, type Supplier } from "@/lib/api/suppliers";
import { todayIn } from "@/lib/format";
import { pickFieldMessages } from "@/lib/forms";

const HEADER_FIELDS = [
  "reason",
  "exit_date",
  "warehouse_id",
  "supplier_id",
  "reference",
  "notes",
] as const;

type HeaderField = (typeof HEADER_FIELDS)[number];
type HeaderErrors = Partial<Record<HeaderField, string>>;

type SupplierChoice = { id: string; name: string };

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

export type ExitPrefill = {
  reason: string;
  warehouseId: string;
  row: ExitLineRow;
};

type ExitFormProps = {
  exit: StockExit | null;
  initialArticles: Map<string, Article>;
  prefill?: ExitPrefill | null;
};

export function ExitForm({ exit, initialArticles, prefill = null }: ExitFormProps) {
  const router = useRouter();
  const { tenant, abilities } = useActiveMembership();
  const mutations = useStockExitMutations();
  const meta = useStockMeta();
  const warehouses = useWarehouses({ search: "", status: "all" });

    const [reason, setReason] = useState<string>(exit?.reason ?? prefill?.reason ?? "loss");
  const [exitDate, setExitDate] = useState(exit?.exit_date ?? todayIn(tenant.timezone));
    const [warehouseId, setWarehouseId] = useState<string | null>(
    exit?.warehouse.id ?? prefill?.warehouseId ?? null,
  );
  const [supplier, setSupplier] = useState<SupplierChoice | null>(exit?.supplier ?? null);
  const [reference, setReference] = useState(exit?.reference ?? "");
  const [notes, setNotes] = useState(exit?.notes ?? "");
  const [rows, setRows] = useState<ExitLineRow[]>(() =>
    exit?.lines && exit.lines.length > 0
      ? exit.lines.map((line) => ({
          key: line.id,
          article: initialArticles.get(line.article.id) ?? null,
          packagingId: line.packaging_id,
          quantity: line.entered_quantity,
          lotId: line.lot?.id ?? null,
        }))
            : [prefill?.row ?? emptyExitRow()],
  );

  const [headerErrors, setHeaderErrors] = useState<HeaderErrors>({});
  const [lineErrors, setLineErrors] = useState<Record<number, ExitLineErrors>>({});
  const [linesError, setLinesError] = useState<string | undefined>();
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [supplierSheetOpen, setSupplierSheetOpen] = useState(false);

  if (!abilities.manage_stock) {
    return (
      <Alert variant="danger" title="Accès réservé">
        Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les
        sorties de stock.
      </Alert>
    );
  }

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

  const reasons = meta.data.exit_reasons;
  const currentReason = reasons.find((item) => item.value === reason);
  const expiredOnly = currentReason?.expired_only ?? false;
  const warehouseList = warehouses.data.data;
  const mainWarehouse = warehouseList.find((item) => item.is_main && item.is_active);
  const effectiveWarehouseId = warehouseId ?? mainWarehouse?.id ?? "";

  const warehouseOptions = warehouseList
    .filter((item) => item.is_active || item.id === effectiveWarehouseId)
    .map((item) => ({ value: item.id, label: item.is_active ? item.name : `${item.name} (désactivé)` }));

  function clearLots() {
    setRows((current) => current.map((row) => ({ ...row, lotId: null })));
  }

    /** Les articles dépendent du stock de l'entrepôt (et du motif « Péremption ») : on les retire quand l'un change. */
  function clearArticles(): boolean {
    const hadArticles = rows.some((row) => row.article !== null);

    setRows((current) =>
      current.map((row) =>
        row.article === null ? row : { ...row, article: null, packagingId: null, quantity: "", lotId: null },
      ),
    );
    setLineErrors({});
    setLinesError(undefined);

    return hadArticles;
  }

  function changeWarehouse(value: string) {
    if (value === effectiveWarehouseId) return;

    setWarehouseId(value);

    if (clearArticles()) {
      toast.info("Les articles ont été retirés des lignes : le stock dépend de l'entrepôt choisi.");
    }

    clearHeaderError("warehouse_id");
  }

  function changeReason(value: string) {
    const next = reasons.find((item) => item.value === value);

    setReason(value);

    if ((next?.expired_only ?? false) !== expiredOnly) {
      if (clearArticles()) {
        toast.info("Les articles ont été retirés des lignes : ce motif ne concerne pas les mêmes lots.");
      }
    } else {
      clearLots();
    }

    clearHeaderError("reason");
  }

  function patchRow(key: string, patch: Partial<ExitLineRow>) {
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    setLineErrors({});
    setLinesError(undefined);
  }

  function clearHeaderError(field: HeaderField) {
    setHeaderErrors((previous) => ({ ...previous, [field]: undefined }));
  }

  /** Ce que les lignes précédentes du même article (sans lot imposé) vont déjà prélever. */
  function requestedBefore(index: number): bigint {
    const current = rows[index];

    if (!current.article) return MICRO_ZERO;

    let sum = MICRO_ZERO;

    for (let position = 0; position < index; position += 1) {
      const other = rows[position];

      if (other.article?.id === current.article.id && other.lotId === null) {
        sum += toMicro(convertedQuantity(other)) ?? MICRO_ZERO;
      }
    }

    return sum;
  }

  function applyError(error: unknown) {
    if (error instanceof ApiError && error.code === "validation_error") {
      const header = pickFieldMessages(error.fieldErrors, HEADER_FIELDS);
      const lines: Record<number, ExitLineErrors> = {};

      for (const [key, list] of Object.entries(error.fieldErrors)) {
        const match = /^lines\.(\d+)\.(\w+)$/.exec(key);

        if (match && list[0]) {
          const index = Number(match[1]);

          lines[index] = { ...lines[index], [match[2] as ExitLineField]: list[0] };
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

    const input: StockExitInput = {
      reason,
      exit_date: exitDate,
      warehouse_id: effectiveWarehouseId,
      supplier_id: currentReason?.requires_supplier ? (supplier?.id ?? null) : null,
      reference: emptyToNull(reference),
      notes: emptyToNull(notes),
      lines: rows.map((row) => ({
        article_id: row.article?.id ?? "",
        packaging_id: row.packagingId,
        entered_quantity: row.quantity,
        lot_id: row.lotId,
      })),
    };

    try {
      const saved = exit
        ? await mutations.update.mutateAsync({ id: exit.id, input })
        : await mutations.create.mutateAsync(input);

      toast.success("Brouillon enregistré.");
      router.push(`/stock-exits/${saved.id}${goToValidation ? "?validate=1" : ""}`);
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
        <FormSection title="Sortie">
          <div className="grid gap-5 sm:grid-cols-2">
            <Field
              label="Motif"
              error={headerErrors.reason}
              hint={
                expiredOnly
                  ? "Seuls les lots expirés peuvent sortir pour ce motif."
                  : "Les lots expirés ne sont pas prélevés automatiquement : utilisez le motif « Péremption »."
              }
            >
              <Select
                size="lg"
                options={reasons.map((item) => ({ value: item.value, label: item.label }))}
                value={reason}
                                onValueChange={changeReason}
              />
            </Field>

            <Field label="Date de la sortie" error={headerErrors.exit_date}>
              <Input
                size="lg"
                type="date"
                value={exitDate}
                max={todayIn(tenant.timezone)}
                onChange={(event) => {
                  setExitDate(event.target.value);
                  clearHeaderError("exit_date");
                }}
              />
            </Field>

            <Field label="Entrepôt d'origine" error={headerErrors.warehouse_id}>
              <Select
                size="lg"
                options={warehouseOptions}
                value={effectiveWarehouseId}
                                onValueChange={changeWarehouse}
                placeholder="Choisir un entrepôt"
              />
            </Field>

            {currentReason?.requires_supplier ? (
              <Field label="Fournisseur" error={headerErrors.supplier_id}>
                <RemoteCombobox<SupplierChoice>
                  size="lg"
                  queryKey={["exit-suppliers"]}
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
            ) : null}

            <Field
              label="Référence"
              optional
              error={headerErrors.reference}
              hint="Bordereau de retour, procès-verbal de destruction…"
            >
              <Input
                size="lg"
                autoComplete="off"
                value={reference}
                onChange={(event) => {
                  setReference(event.target.value);
                  clearHeaderError("reference");
                }}
              />
            </Field>
          </div>

          <Field
            label="Notes"
            optional={!currentReason?.requires_notes}
            error={headerErrors.notes}
            hint={currentReason?.requires_notes ? "Expliquez la raison de cette sortie." : undefined}
          >
            <Textarea
              placeholder="Circonstances, remarques…"
              value={notes}
              onChange={(event) => {
                setNotes(event.target.value);
                clearHeaderError("notes");
              }}
            />
          </Field>
        </FormSection>

        <FormSection
          title="Articles sortis"
          description="Le stock est vérifié et prélevé à la validation. Le coût est calculé au coût d'achat des lots prélevés."
        >
          <ul className="space-y-4">
            {rows.map((row, index) => (
              <ExitLineEditor
                key={row.key}
                row={row}
                index={index}
                errors={lineErrors[index] ?? {}}
                warehouseId={effectiveWarehouseId}
                expiredOnly={expiredOnly}
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
            onClick={() => setRows((current) => [...current, emptyExitRow()])}
          >
            <Plus strokeWidth={1.5} aria-hidden />
            Ajouter une ligne
          </Button>

          <p className="border-t pt-5 text-sm text-muted-foreground">
            {rows.length} {rows.length > 1 ? "lignes" : "ligne"}
          </p>
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
            onClick={() => router.push(exit ? `/stock-exits/${exit.id}` : "/stock-exits")}
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
    </>
  );
}