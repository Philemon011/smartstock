"use client";

import { Plus } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";
import { useActiveMembership } from "@/components/session/session-provider";
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
import { Button, buttonVariants } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { FormSection } from "@/components/ui/form-section";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useStockTransferMutations } from "@/hooks/use-stock-transfers";
import { useWarehouses } from "@/hooks/use-warehouses";
import type { Article } from "@/lib/api/articles";
import { ApiError } from "@/lib/api/client";
import type { StockTransfer, StockTransferInput } from "@/lib/api/stock-transfers";
import { todayIn } from "@/lib/format";
import { pickFieldMessages } from "@/lib/forms";

const HEADER_FIELDS = ["transfer_date", "from_warehouse_id", "to_warehouse_id", "reference", "notes"] as const;

type HeaderField = (typeof HEADER_FIELDS)[number];
type HeaderErrors = Partial<Record<HeaderField, string>>;

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

type TransferFormProps = {
  transfer: StockTransfer | null;
  initialArticles: Map<string, Article>;
};

export function TransferForm({ transfer, initialArticles }: TransferFormProps) {
  const router = useRouter();
  const { tenant, abilities } = useActiveMembership();
  const mutations = useStockTransferMutations();
  const warehouses = useWarehouses({ search: "", status: "all" });

  const [transferDate, setTransferDate] = useState(transfer?.transfer_date ?? todayIn(tenant.timezone));
  const [fromId, setFromId] = useState<string | null>(transfer?.from_warehouse.id ?? null);
  const [toId, setToId] = useState<string | null>(transfer?.to_warehouse.id ?? null);
  const [reference, setReference] = useState(transfer?.reference ?? "");
  const [notes, setNotes] = useState(transfer?.notes ?? "");
  const [rows, setRows] = useState<ExitLineRow[]>(() =>
    transfer?.lines && transfer.lines.length > 0
      ? transfer.lines.map((line) => ({
          key: line.id,
          article: initialArticles.get(line.article.id) ?? null,
          packagingId: line.packaging_id,
          quantity: line.entered_quantity,
          lotId: line.lot?.id ?? null,
        }))
      : [emptyExitRow()],
  );

  const [headerErrors, setHeaderErrors] = useState<HeaderErrors>({});
  const [lineErrors, setLineErrors] = useState<Record<number, ExitLineErrors>>({});
  const [linesError, setLinesError] = useState<string | undefined>();
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  if (!abilities.transfer) {
    return (
      <Alert variant="danger" title="Accès réservé">
        Seuls le propriétaire, les administrateurs, les gestionnaires de stock et les vendeurs peuvent gérer les
        transferts.
      </Alert>
    );
  }

  if (warehouses.isPending) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-64 w-full rounded-xl" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }

  if (warehouses.isError) {
    return (
      <Alert variant="danger" title="Impossible de préparer le formulaire">
        Impossible de charger les entrepôts. Rechargez la page, puis réessayez.
      </Alert>
    );
  }

  const list = warehouses.data.data;
  const active = list.filter((item) => item.is_active);

  if (!transfer && active.length < 2) {
    return (
      <Alert variant="info" title="Il faut deux entrepôts pour transférer du stock">
        <p>Créez d&apos;abord un second entrepôt actif : un transfert déplace le stock de l&apos;un vers l&apos;autre.</p>
        <Link href="/warehouses" className={`${buttonVariants({ variant: "secondary", size: "sm" })} mt-3`}>
          Gérer les entrepôts
        </Link>
      </Alert>
    );
  }

  const main = list.find((item) => item.is_main && item.is_active);
  const effectiveFrom = fromId ?? main?.id ?? "";
  const others = list.filter((item) => item.is_active && item.id !== effectiveFrom);
  // Quand il n'existe qu'une seule destination possible, elle est choisie d'office.
  const effectiveTo = toId ?? (others.length === 1 ? others[0].id : "");

  const label = (item: (typeof list)[number]) => (item.is_active ? item.name : `${item.name} (désactivé)`);

  const fromOptions = list
    .filter((item) => item.is_active || item.id === effectiveFrom)
    .map((item) => ({ value: item.id, label: label(item) }));

  const toOptions = list
    .filter((item) => item.id !== effectiveFrom && (item.is_active || item.id === effectiveTo))
    .map((item) => ({ value: item.id, label: label(item) }));

  function clearHeaderError(field: HeaderField) {
    setHeaderErrors((previous) => ({ ...previous, [field]: undefined }));
  }

  function patchRow(key: string, patch: Partial<ExitLineRow>) {
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    setLineErrors({});
    setLinesError(undefined);
  }

  /** Le stock dépend de l'entrepôt d'origine : en changer retire les articles déjà choisis. */
  function changeFrom(value: string) {
    if (value === effectiveFrom) return;

    setFromId(value);

    if (toId === value) setToId(null);

    if (rows.some((row) => row.article !== null)) {
      setRows((current) =>
        current.map((row) =>
          row.article === null ? row : { ...row, article: null, packagingId: null, quantity: "", lotId: null },
        ),
      );
      setLineErrors({});
      setLinesError(undefined);
      toast.info("Les articles ont été retirés des lignes : le stock dépend de l'entrepôt d'origine.");
    }

    clearHeaderError("from_warehouse_id");
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

      for (const [key, messages] of Object.entries(error.fieldErrors)) {
        const match = /^lines\.(\d+)\.(\w+)$/.exec(key);

        if (match && messages[0]) {
          const index = Number(match[1]);

          lines[index] = { ...lines[index], [match[2] as ExitLineField]: messages[0] };
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

    const input: StockTransferInput = {
      transfer_date: transferDate,
      from_warehouse_id: effectiveFrom,
      to_warehouse_id: effectiveTo,
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
      const saved = transfer
        ? await mutations.update.mutateAsync({ id: transfer.id, input })
        : await mutations.create.mutateAsync(input);

      toast.success("Brouillon enregistré.");
      router.push(`/stock-transfers/${saved.id}${goToValidation ? "?validate=1" : ""}`);
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
    <form noValidate onSubmit={handleSubmit} aria-busy={submitting || undefined} className="space-y-6">
      <FormSection title="Transfert">
        <div className="grid gap-5 sm:grid-cols-2">
          <Field label="Entrepôt d'origine" error={headerErrors.from_warehouse_id}>
            <Select
              size="lg"
              options={fromOptions}
              value={effectiveFrom}
              onValueChange={changeFrom}
              placeholder="Choisir un entrepôt"
            />
          </Field>

          <Field label="Entrepôt de destination" error={headerErrors.to_warehouse_id}>
            <Select
              size="lg"
              options={toOptions}
              value={effectiveTo}
              onValueChange={(value) => {
                setToId(value);
                clearHeaderError("to_warehouse_id");
              }}
              placeholder="Choisir un entrepôt"
            />
          </Field>

          <Field label="Date du transfert" error={headerErrors.transfer_date}>
            <Input
              size="lg"
              type="date"
              value={transferDate}
              max={todayIn(tenant.timezone)}
              onChange={(event) => {
                setTransferDate(event.target.value);
                clearHeaderError("transfer_date");
              }}
            />
          </Field>

          <Field label="Référence" optional error={headerErrors.reference} hint="Bon de transfert, numéro de camion…">
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

        <Field label="Notes" optional error={headerErrors.notes}>
          <Textarea
            placeholder="Motif du transfert, remarques…"
            value={notes}
            onChange={(event) => {
              setNotes(event.target.value);
              clearHeaderError("notes");
            }}
          />
        </Field>
      </FormSection>

      <FormSection
        title="Articles transférés"
        description="Le stock est vérifié et déplacé à la validation. Le coût d'achat, l'expiration et le numéro de lot suivent la marchandise."
      >
        <ul className="space-y-4">
          {rows.map((row, index) => (
            <ExitLineEditor
              key={row.key}
              row={row}
              index={index}
              errors={lineErrors[index] ?? {}}
              warehouseId={effectiveFrom}
              expiredOnly={false}
              variant="transfer"
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
          onClick={() => router.push(transfer ? `/stock-transfers/${transfer.id}` : "/stock-transfers")}
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
  );
}