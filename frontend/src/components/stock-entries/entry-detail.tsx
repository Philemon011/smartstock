"use client";

import { ArrowLeft, FileText, Paperclip, Pencil, Trash2, Undo2 } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useRef, useState } from "react";
import { toast } from "sonner";
import { EntryStatusBadge } from "@/components/stock-entries/entry-status-badge";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { Field } from "@/components/ui/field";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useStockEntry, useStockEntryMutations } from "@/hooks/use-stock-entries";
import { ApiError } from "@/lib/api/client";
import type { StockEntry, StockEntryLine } from "@/lib/api/stock-entries";
import { formatDate, formatDateTime, formatMoney, formatQuantity } from "@/lib/format";
import { PackagePlus } from "lucide-react";

const MAX_DOCUMENT_BYTES = 2 * 1024 * 1024;

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

function quantityLabel(line: StockEntryLine): string {
  return `${formatQuantity(line.entered_quantity)} ${line.entered_unit}`;
}

function stockQuantityLabel(line: StockEntryLine): string | null {
  return line.entered_unit === line.article.unit.symbol
    ? null
    : `= ${formatQuantity(line.quantity)} ${line.article.unit.symbol}`;
}

function LinesTable({ lines, currency }: { lines: StockEntryLine[]; currency: string }) {
  return (
    <div className="hidden overflow-x-auto md:block">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b text-xs text-muted-foreground">
            <th className="h-11 pr-4 font-medium">Article</th>
            <th className="pr-4 font-medium">Quantité</th>
            <th className="pr-4 text-right font-medium">Prix d&apos;achat</th>
            <th className="pr-4 font-medium">Expiration</th>
            <th className="pr-4 font-medium">Lot</th>
            <th className="text-right font-medium">Total</th>
          </tr>
        </thead>
        <tbody>
          {lines.map((line) => (
            <tr key={line.id} className="border-b last:border-b-0">
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
              <td className="py-4 pr-4 text-right tabular-nums text-foreground">
                {formatMoney(line.unit_price, currency)}
                <span className="text-xs text-muted-foreground"> / {line.entered_unit}</span>
              </td>
              <td className="py-4 pr-4 text-muted-foreground">
                {line.expiry_date ? formatDate(line.expiry_date) : "—"}
              </td>
              <td className="py-4 pr-4">
                {line.lot ? <Badge>{line.lot.code}</Badge> : <span className="text-muted-foreground">—</span>}
                {line.lot_number ? (
                  <p className="mt-1 text-xs text-muted-foreground">{line.lot_number}</p>
                ) : null}
              </td>
              <td className="py-4 text-right font-medium tabular-nums text-foreground">
                {formatMoney(line.line_total, currency)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function LinesCards({ lines, currency }: { lines: StockEntryLine[]; currency: string }) {
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
                <span className="block text-xs font-normal text-muted-foreground">
                  {stockQuantityLabel(line)}
                </span>
              ) : null}
            </Detail>
            <Detail label="Prix d'achat">
              {formatMoney(line.unit_price, currency)}
              <span className="text-xs font-normal text-muted-foreground"> / {line.entered_unit}</span>
            </Detail>
            <Detail label="Expiration">{line.expiry_date ? formatDate(line.expiry_date) : "—"}</Detail>
            <Detail label="Lot">{line.lot?.code ?? line.lot_number ?? "—"}</Detail>
          </dl>

          <p className="mt-3 border-t pt-3 text-right text-sm font-semibold tabular-nums text-foreground">
            {formatMoney(line.line_total, currency)}
          </p>
        </li>
      ))}
    </ul>
  );
}

function DocumentPanel({ entry }: { entry: StockEntry }) {
  const mutations = useStockEntryMutations();
  const inputRef = useRef<HTMLInputElement>(null);
  const [removeOpen, setRemoveOpen] = useState(false);

  async function upload(file: File) {
    if (file.size > MAX_DOCUMENT_BYTES) {
      toast.error("Le fichier ne peut pas dépasser 2 Mo.");
      return;
    }

    try {
      await mutations.uploadDocument.mutateAsync({ id: entry.id, file });
      toast.success("Bon enregistré.");
    } catch (error) {
      toast.error(errorMessage(error));
    }
  }

  async function remove() {
    try {
      await mutations.removeDocument.mutateAsync(entry.id);
      toast.success("Bon retiré.");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setRemoveOpen(false);
    }
  }

  return (
    <section className="rounded-xl border bg-card p-6 shadow-card">
      <h2 className="text-lg">Bon de livraison</h2>

      {entry.document ? (
        <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <a
            href={entry.document.url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex min-w-0 items-center gap-2.5 text-sm font-medium text-primary underline-offset-4 hover:underline"
          >
            <FileText className="size-4 shrink-0" strokeWidth={1.5} aria-hidden />
            <span className="truncate">{entry.document.name ?? "Ouvrir le bon"}</span>
          </a>

          {entry.can.document ? (
            <div className="flex gap-2">
              <Button
                variant="secondary"
                size="sm"
                loading={mutations.uploadDocument.isPending}
                onClick={() => inputRef.current?.click()}
              >
                Remplacer
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setRemoveOpen(true)}>
                Retirer
              </Button>
            </div>
          ) : null}
        </div>
      ) : (
        <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted-foreground">
            Aucun bon joint. PDF ou image (JPG, PNG, WebP), 2 Mo maximum.
          </p>

          {entry.can.document ? (
            <Button
              variant="secondary"
              size="sm"
              loading={mutations.uploadDocument.isPending}
              onClick={() => inputRef.current?.click()}
            >
              <Paperclip strokeWidth={1.5} aria-hidden />
              Joindre le bon
            </Button>
          ) : null}
        </div>
      )}

      <input
        ref={inputRef}
        type="file"
        accept="application/pdf,image/jpeg,image/png,image/webp"
        className="sr-only"
        tabIndex={-1}
        aria-label="Choisir le fichier du bon"
        onChange={(event) => {
          const file = event.target.files?.[0];

          if (file) {
            void upload(file);
          }

          event.target.value = "";
        }}
      />

      <ConfirmDialog
        open={removeOpen}
        onOpenChange={(open) => {
          if (!open && !mutations.removeDocument.isPending) setRemoveOpen(false);
        }}
        title="Retirer le bon ?"
        description="Le fichier sera supprimé définitivement. L'entrée et le stock ne changent pas."
        confirmLabel="Retirer"
        destructive
        pending={mutations.removeDocument.isPending}
        onConfirm={() => void remove()}
      />
    </section>
  );
}

function ValidateDialog({
  entry,
  open,
  onOpenChange,
  currency,
}: {
  entry: StockEntry;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  currency: string;
}) {
  const router = useRouter();
  const mutations = useStockEntryMutations();
  const [error, setError] = useState<string | null>(null);

  const count = entry.lines?.length ?? entry.lines_count ?? 0;

  async function confirm() {
    setError(null);

    try {
      const validated = await mutations.validate.mutateAsync(entry.id);

      toast.success(`Entrée ${validated.number} validée : le stock est à jour.`);
      onOpenChange(false);
      router.replace(`/stock-entries/${entry.id}`);
    } catch (caught) {
      setError(errorMessage(caught));
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => {
        if (!next && !mutations.validate.isPending) {
          setError(null);
          onOpenChange(false);
        }
      }}
      title="Valider cette entrée ?"
      description="Le stock sera mis à jour immédiatement. Une entrée validée ne se modifie plus : en cas d'erreur, elle devra être annulée."
      confirmLabel="Valider l'entrée"
      pending={mutations.validate.isPending}
      onConfirm={() => void confirm()}
      error={error}
    >
      <dl className="space-y-2 rounded-lg bg-accent/60 p-4 text-sm">
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Entrepôt</dt>
          <dd className="font-medium text-foreground">{entry.warehouse.name}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Lignes</dt>
          <dd className="font-medium text-foreground">{count}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Total</dt>
          <dd className="font-semibold tabular-nums text-foreground">
            {entry.total_amount ? formatMoney(entry.total_amount, currency) : "—"}
          </dd>
        </div>
      </dl>
    </ConfirmDialog>
  );
}

function CancelDialog({
  entry,
  open,
  onOpenChange,
}: {
  entry: StockEntry;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const mutations = useStockEntryMutations();
  const [reason, setReason] = useState("");
  const [fieldError, setFieldError] = useState<string | undefined>();
  const [error, setError] = useState<string | null>(null);

  function reset() {
    setReason("");
    setFieldError(undefined);
    setError(null);
  }

  async function confirm() {
    setFieldError(undefined);
    setError(null);

    try {
      await mutations.cancel.mutateAsync({ id: entry.id, reason });
      toast.success("Entrée annulée : le stock a été corrigé.");
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
      title="Annuler cette entrée ?"
      description="Les lots de cette entrée sortent du stock. L'entrée et son historique sont conservés. C'est possible tant que la marchandise n'a pas bougé."
      confirmLabel="Annuler l'entrée"
      destructive
      pending={mutations.cancel.isPending}
      onConfirm={() => void confirm()}
      error={error}
    >
      <Field label="Pourquoi annulez-vous cette entrée ?" error={fieldError}>
        <Textarea
          value={reason}
          placeholder="Erreur de saisie, livraison refusée…"
          onChange={(event) => {
            setReason(event.target.value);
            setFieldError(undefined);
          }}
        />
      </Field>
    </ConfirmDialog>
  );
}

export function EntryDetail({ id, openValidation }: { id: string; openValidation: boolean }) {
  const router = useRouter();
  const { tenant } = useActiveMembership();
  const query = useStockEntry(id);
  const mutations = useStockEntryMutations();

  const [validateOpen, setValidateOpen] = useState(openValidation);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

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
        icon={PackagePlus}
        title="Entrée introuvable"
        description="Cette entrée n'existe pas ou a été supprimée."
        action={
          <Link href="/stock-entries" className={buttonVariants({ variant: "secondary" })}>
            Retour aux entrées
          </Link>
        }
      />
    ) : (
      <div className="space-y-4">
        <Alert variant="danger" title="Impossible de charger l'entrée">
          {errorMessage(query.error)}
        </Alert>
        <Button variant="secondary" onClick={() => void query.refetch()}>
          Réessayer
        </Button>
      </div>
    );
  }

  const entry = query.data;
  const lines = entry.lines ?? [];

  async function remove() {
    try {
      await mutations.remove.mutateAsync(entry.id);
      toast.success("Brouillon supprimé.");
      router.replace("/stock-entries");
    } catch (error) {
      toast.error(errorMessage(error));
      setDeleteOpen(false);
    }
  }

  return (
    <div className="space-y-8">
      <Link
        href="/stock-entries"
        className="inline-flex items-center gap-2 text-sm text-muted-foreground transition-colors duration-200 ease-smooth hover:text-foreground"
      >
        <ArrowLeft className="size-4" strokeWidth={1.5} aria-hidden />
        Entrées de stock
      </Link>

      <header className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-2">
          <h1 className="text-3xl">{entry.number ?? "Brouillon"}</h1>
          <div className="flex flex-wrap items-center gap-2">
            <EntryStatusBadge status={entry.status} label={entry.status_label} />
            <Badge>{entry.reason_label}</Badge>
            <span className="text-sm text-muted-foreground">{formatDate(entry.entry_date)}</span>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          {entry.can.validate ? (
            <Button onClick={() => setValidateOpen(true)}>Valider l&apos;entrée</Button>
          ) : null}

          {entry.can.update ? (
            <Link href={`/stock-entries/${entry.id}/edit`} className={buttonVariants({ variant: "secondary" })}>
              <span className="inline-flex items-center gap-2">
                <Pencil className="size-4" strokeWidth={1.5} aria-hidden />
                Modifier
              </span>
            </Link>
          ) : null}

          {entry.can.cancel ? (
            <Button variant="secondary" onClick={() => setCancelOpen(true)}>
              <Undo2 strokeWidth={1.5} aria-hidden />
              Annuler l&apos;entrée
            </Button>
          ) : null}

          {entry.can.delete ? (
            <Button variant="destructive" onClick={() => setDeleteOpen(true)}>
              <Trash2 strokeWidth={1.5} aria-hidden />
              Supprimer
            </Button>
          ) : null}
        </div>
      </header>

      {entry.status === "draft" ? (
        <Alert variant="warning" title="Brouillon">
          Cette entrée n&apos;a pas encore d&apos;effet sur le stock. Validez-la quand la livraison est correcte.
        </Alert>
      ) : null}

      {entry.status === "cancelled" ? (
        <Alert variant="danger" title="Entrée annulée">
          Annulée {entry.cancelled_at ? `le ${formatDateTime(entry.cancelled_at)}` : ""}
          {entry.cancelled_by ? ` par ${entry.cancelled_by}` : ""}. Motif : {entry.cancel_reason}
        </Alert>
      ) : null}

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Livraison</h2>

        <dl className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <Detail label="Entrepôt">{entry.warehouse.name}</Detail>
          <Detail label="Fournisseur">{entry.supplier?.name ?? "—"}</Detail>
          <Detail label="Numéro du bon">{entry.reference ?? "—"}</Detail>
          <Detail label="Saisie par">{entry.created_by ?? "—"}</Detail>
          {entry.validated_at ? (
            <Detail label="Validée">
              {formatDateTime(entry.validated_at)}
              {entry.validated_by ? ` · ${entry.validated_by}` : ""}
            </Detail>
          ) : null}
        </dl>

        {entry.notes ? (
          <p className="mt-6 border-t pt-5 text-sm text-muted-foreground">{entry.notes}</p>
        ) : null}
      </section>

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Articles reçus</h2>

        <div className="mt-5">
          <LinesTable lines={lines} currency={tenant.currency} />
          <LinesCards lines={lines} currency={tenant.currency} />
        </div>

        <div className="mt-5 flex items-center justify-between gap-4 border-t pt-5">
          <p className="text-sm text-muted-foreground">
            {lines.length} {lines.length > 1 ? "lignes" : "ligne"}
          </p>
          <p className="text-right text-sm text-muted-foreground">
            Total
            <span className="block text-2xl font-semibold tabular-nums text-foreground">
              {entry.total_amount ? formatMoney(entry.total_amount, tenant.currency) : "—"}
            </span>
          </p>
        </div>
      </section>

      <DocumentPanel entry={entry} />

      <ValidateDialog
        entry={entry}
        open={validateOpen && entry.can.validate}
        onOpenChange={setValidateOpen}
        currency={tenant.currency}
      />

      <CancelDialog entry={entry} open={cancelOpen} onOpenChange={setCancelOpen} />

      <ConfirmDialog
        open={deleteOpen}
        onOpenChange={(open) => {
          if (!open && !mutations.remove.isPending) setDeleteOpen(false);
        }}
        title="Supprimer ce brouillon ?"
        description="Ce brouillon sera supprimé définitivement, avec son bon s'il en a un. Le stock n'est pas concerné."
        confirmLabel="Supprimer"
        destructive
        pending={mutations.remove.isPending}
        onConfirm={() => void remove()}
      />
    </div>
  );
}