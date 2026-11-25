"use client";

import { ArrowLeft, ArrowLeftRight, ArrowRight, Pencil, Trash2, Undo2 } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";
import { useActiveMembership } from "@/components/session/session-provider";
import { EntryStatusBadge } from "@/components/stock-entries/entry-status-badge";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { Field } from "@/components/ui/field";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useStockTransfer, useStockTransferMutations } from "@/hooks/use-stock-transfers";
import { ApiError } from "@/lib/api/client";
import type { StockTransfer, StockTransferLine } from "@/lib/api/stock-transfers";
import { formatDate, formatDateTime, formatMoney, formatQuantity } from "@/lib/format";

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

function Route({ transfer }: { transfer: StockTransfer }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
      <span>{transfer.from_warehouse.name}</span>
      <ArrowRight className="size-4 shrink-0 text-muted-foreground" strokeWidth={1.5} aria-hidden />
      <span>{transfer.to_warehouse.name}</span>
    </span>
  );
}

function quantityLabel(line: StockTransferLine): string {
  return `${formatQuantity(line.entered_quantity)} ${line.entered_unit}`;
}

function stockQuantityLabel(line: StockTransferLine): string | null {
  return line.entered_unit === line.article.unit.symbol
    ? null
    : `= ${formatQuantity(line.quantity)} ${line.article.unit.symbol}`;
}

function LotsCell({ line }: { line: StockTransferLine }) {
  if (line.allocations.length > 0) {
    return (
      <ul className="space-y-2">
        {line.allocations.map((allocation) => (
          <li key={allocation.id} className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <Badge>{allocation.source_lot.code}</Badge>
            <ArrowRight className="size-3.5 text-muted-foreground" strokeWidth={1.5} aria-hidden />
            <Badge>{allocation.destination_lot.code}</Badge>
            <span className="text-sm tabular-nums text-foreground">
              {formatQuantity(allocation.quantity)} {line.article.unit.symbol}
            </span>
            {allocation.source_lot.expiry_date ? (
              <span className="text-xs text-muted-foreground">
                exp. {formatDate(allocation.source_lot.expiry_date)}
              </span>
            ) : null}
          </li>
        ))}
      </ul>
    );
  }

  if (line.lot) {
    return (
      <p className="text-sm text-muted-foreground">
        Lot imposé : <span className="font-medium text-foreground">{line.lot.code}</span>
      </p>
    );
  }

  return <p className="text-sm text-muted-foreground">Le lot qui expire en premier</p>;
}

function costOf(line: StockTransferLine, currency: string): string {
  return line.cost_total ? formatMoney(line.cost_total, currency) : "—";
}

function LinesTable({ lines, currency, showCost }: { lines: StockTransferLine[]; currency: string; showCost: boolean }) {
  return (
    <div className="hidden overflow-x-auto md:block">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b text-xs text-muted-foreground">
            <th className="h-11 pr-4 font-medium">Article</th>
            <th className="pr-4 font-medium">Quantité</th>
            <th className="pr-4 font-medium">Lots déplacés</th>
            {showCost ? <th className="text-right font-medium">Coût</th> : null}
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
              <td className="py-4 pr-4">
                <LotsCell line={line} />
              </td>
              {showCost ? (
                <td className="py-4 text-right font-medium tabular-nums text-foreground">{costOf(line, currency)}</td>
              ) : null}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function LinesCards({ lines, currency, showCost }: { lines: StockTransferLine[]; currency: string; showCost: boolean }) {
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

          <dl className="mt-3 space-y-3 text-sm">
            <Detail label="Quantité">
              {quantityLabel(line)}
              {stockQuantityLabel(line) ? (
                <span className="block text-xs font-normal text-muted-foreground">{stockQuantityLabel(line)}</span>
              ) : null}
            </Detail>
            <div>
              <dt className="text-sm text-muted-foreground">Lots déplacés</dt>
              <dd className="mt-1">
                <LotsCell line={line} />
              </dd>
            </div>
          </dl>

          {showCost ? (
            <p className="mt-3 border-t pt-3 text-right text-sm font-semibold tabular-nums text-foreground">
              {costOf(line, currency)}
            </p>
          ) : null}
        </li>
      ))}
    </ul>
  );
}

function ValidateDialog({
  transfer,
  open,
  onOpenChange,
}: {
  transfer: StockTransfer;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const router = useRouter();
  const mutations = useStockTransferMutations();
  const [error, setError] = useState<string | null>(null);

  const count = transfer.lines?.length ?? transfer.lines_count ?? 0;

  async function confirm() {
    setError(null);

    try {
      const validated = await mutations.validate.mutateAsync(transfer.id);

      toast.success(`Transfert ${validated.number} validé : le stock est arrivé dans ${validated.to_warehouse.name}.`);
      onOpenChange(false);
      router.replace(`/stock-transfers/${transfer.id}`);
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
      title="Valider ce transfert ?"
      description="Le stock sort de l'entrepôt d'origine et arrive tout de suite dans l'entrepôt de destination, avec la même expiration et le même coût. Un transfert validé ne se modifie plus : en cas d'erreur, il devra être annulé."
      confirmLabel="Valider le transfert"
      pending={mutations.validate.isPending}
      onConfirm={() => void confirm()}
      error={error}
    >
      <dl className="space-y-2 rounded-lg bg-accent/60 p-4 text-sm">
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Origine</dt>
          <dd className="font-medium text-foreground">{transfer.from_warehouse.name}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Destination</dt>
          <dd className="font-medium text-foreground">{transfer.to_warehouse.name}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Lignes</dt>
          <dd className="font-medium text-foreground">{count}</dd>
        </div>
      </dl>
    </ConfirmDialog>
  );
}

function CancelDialog({
  transfer,
  open,
  onOpenChange,
}: {
  transfer: StockTransfer;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const mutations = useStockTransferMutations();
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
      await mutations.cancel.mutateAsync({ id: transfer.id, reason });
      toast.success(`Transfert annulé : le stock est revenu dans ${transfer.from_warehouse.name}.`);
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
      title="Annuler ce transfert ?"
      description={`Les quantités retournent dans ${transfer.from_warehouse.name}, dans les lots d'où elles viennent, et les lots arrivés dans ${transfer.to_warehouse.name} sont retirés. C'est impossible si ce stock a déjà été vendu, sorti ou transféré à nouveau. Le transfert et son historique sont conservés.`}
      confirmLabel="Annuler le transfert"
      destructive
      pending={mutations.cancel.isPending}
      onConfirm={() => void confirm()}
      error={error}
    >
      <Field label="Pourquoi annulez-vous ce transfert ?" error={fieldError}>
        <Textarea
          value={reason}
          placeholder="Erreur de saisie, mauvais entrepôt…"
          onChange={(event) => {
            setReason(event.target.value);
            setFieldError(undefined);
          }}
        />
      </Field>
    </ConfirmDialog>
  );
}

export function TransferDetail({ id, openValidation }: { id: string; openValidation: boolean }) {
  const router = useRouter();
  const { tenant } = useActiveMembership();
  const query = useStockTransfer(id);
  const mutations = useStockTransferMutations();

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
        icon={ArrowLeftRight}
        title="Transfert introuvable"
        description="Ce transfert n'existe pas ou a été supprimé."
        action={
          <Link href="/stock-transfers" className={buttonVariants({ variant: "secondary" })}>
            Retour aux transferts
          </Link>
        }
      />
    ) : (
      <div className="space-y-4">
        <Alert variant="danger" title="Impossible de charger le transfert">
          {errorMessage(query.error)}
        </Alert>
        <Button variant="secondary" onClick={() => void query.refetch()}>
          Réessayer
        </Button>
      </div>
    );
  }

  const transfer = query.data;
  const lines = transfer.lines ?? [];
  const currency = tenant.currency;
  const showCost = transfer.total_cost !== undefined;

  async function remove() {
    try {
      await mutations.remove.mutateAsync(transfer.id);
      toast.success("Brouillon supprimé.");
      router.replace("/stock-transfers");
    } catch (error) {
      toast.error(errorMessage(error));
      setDeleteOpen(false);
    }
  }

  return (
    <div className="space-y-8">
      <Link
        href="/stock-transfers"
        className="inline-flex items-center gap-2 text-sm text-muted-foreground transition-colors duration-200 ease-smooth hover:text-foreground"
      >
        <ArrowLeft className="size-4" strokeWidth={1.5} aria-hidden />
        Transferts de stock
      </Link>

      <header className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-2">
          <h1 className="text-3xl">{transfer.number ?? "Brouillon"}</h1>
          <div className="flex flex-wrap items-center gap-2">
            <EntryStatusBadge status={transfer.status} label={transfer.status_label} />
            <span className="text-sm text-muted-foreground">{formatDate(transfer.transfer_date)}</span>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          {transfer.can.validate ? (
            <Button onClick={() => setValidateOpen(true)}>Valider le transfert</Button>
          ) : null}

          {transfer.can.update ? (
            <Link href={`/stock-transfers/${transfer.id}/edit`} className={buttonVariants({ variant: "secondary" })}>
              <span className="inline-flex items-center gap-2">
                <Pencil className="size-4" strokeWidth={1.5} aria-hidden />
                Modifier
              </span>
            </Link>
          ) : null}

          {transfer.can.cancel ? (
            <Button variant="secondary" onClick={() => setCancelOpen(true)}>
              <Undo2 strokeWidth={1.5} aria-hidden />
              Annuler le transfert
            </Button>
          ) : null}

          {transfer.can.delete ? (
            <Button variant="destructive" onClick={() => setDeleteOpen(true)}>
              <Trash2 strokeWidth={1.5} aria-hidden />
              Supprimer
            </Button>
          ) : null}
        </div>
      </header>

      {transfer.status === "draft" ? (
        <Alert variant="warning" title="Brouillon">
          Ce transfert n&apos;a pas encore d&apos;effet sur le stock. Validez-le quand il est correct : les lots
          seront alors déplacés.
        </Alert>
      ) : null}

      {transfer.status === "cancelled" ? (
        <Alert variant="danger" title="Transfert annulé">
          Annulé {transfer.cancelled_at ? `le ${formatDateTime(transfer.cancelled_at)}` : ""}
          {transfer.cancelled_by ? ` par ${transfer.cancelled_by}` : ""}. Motif : {transfer.cancel_reason}. Le stock
          est revenu dans {transfer.from_warehouse.name}.
        </Alert>
      ) : null}

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Transfert</h2>

        <dl className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <Detail label="Trajet">
            <Route transfer={transfer} />
          </Detail>
          <Detail label="Référence">{transfer.reference ?? "—"}</Detail>
          <Detail label="Saisi par">{transfer.created_by ?? "—"}</Detail>
          {transfer.validated_at ? (
            <Detail label="Validé">
              {formatDateTime(transfer.validated_at)}
              {transfer.validated_by ? ` · ${transfer.validated_by}` : ""}
            </Detail>
          ) : null}
        </dl>

        {transfer.notes ? <p className="mt-6 border-t pt-5 text-sm text-muted-foreground">{transfer.notes}</p> : null}
      </section>

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Articles transférés</h2>

        <div className="mt-5">
          <LinesTable lines={lines} currency={currency} showCost={showCost} />
          <LinesCards lines={lines} currency={currency} showCost={showCost} />
        </div>

        <div className="mt-5 flex items-center justify-between gap-4 border-t pt-5">
          <p className="text-sm text-muted-foreground">
            {lines.length} {lines.length > 1 ? "lignes" : "ligne"}
          </p>

          {showCost ? (
            <p className="text-right text-sm text-muted-foreground">
              Valeur déplacée
              <span className="block text-2xl font-semibold tabular-nums text-foreground">
                {transfer.total_cost ? formatMoney(transfer.total_cost, currency) : "—"}
              </span>
            </p>
          ) : null}
        </div>
      </section>

      <ValidateDialog transfer={transfer} open={validateOpen && transfer.can.validate} onOpenChange={setValidateOpen} />

      <CancelDialog transfer={transfer} open={cancelOpen} onOpenChange={setCancelOpen} />

      <ConfirmDialog
        open={deleteOpen}
        onOpenChange={(open) => {
          if (!open && !mutations.remove.isPending) setDeleteOpen(false);
        }}
        title="Supprimer ce brouillon ?"
        description="Ce brouillon sera supprimé définitivement. Le stock n'est pas concerné."
        confirmLabel="Supprimer"
        destructive
        pending={mutations.remove.isPending}
        onConfirm={() => void remove()}
      />
    </div>
  );
}