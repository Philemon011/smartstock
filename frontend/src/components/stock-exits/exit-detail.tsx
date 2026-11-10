"use client";

import { ArrowLeft, PackageMinus, Pencil, Trash2, Undo2 } from "lucide-react";
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
import { useStockExit, useStockExitMutations } from "@/hooks/use-stock-exits";
import { ApiError } from "@/lib/api/client";
import type { StockExit, StockExitLine } from "@/lib/api/stock-exits";
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

function quantityLabel(line: StockExitLine): string {
  return `${formatQuantity(line.entered_quantity)} ${line.entered_unit}`;
}

function stockQuantityLabel(line: StockExitLine): string | null {
  return line.entered_unit === line.article.unit.symbol
    ? null
    : `= ${formatQuantity(line.quantity)} ${line.article.unit.symbol}`;
}

function LotsCell({ line }: { line: StockExitLine }) {
  if (line.allocations.length > 0) {
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

  if (line.lot) {
    return (
      <p className="text-sm text-muted-foreground">
        Lot imposé : <span className="font-medium text-foreground">{line.lot.code}</span>
      </p>
    );
  }

  return <p className="text-sm text-muted-foreground">Le lot qui expire en premier</p>;
}

function LinesTable({ lines, currency }: { lines: StockExitLine[]; currency: string }) {
  return (
    <div className="hidden overflow-x-auto md:block">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b text-xs text-muted-foreground">
            <th className="h-11 pr-4 font-medium">Article</th>
            <th className="pr-4 font-medium">Quantité</th>
            <th className="pr-4 font-medium">Lots prélevés</th>
            <th className="text-right font-medium">Coût</th>
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
              <td className="py-4 text-right font-medium tabular-nums text-foreground">
                {line.cost_total ? formatMoney(line.cost_total, currency) : "—"}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function LinesCards({ lines, currency }: { lines: StockExitLine[]; currency: string }) {
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
                <span className="block text-xs font-normal text-muted-foreground">
                  {stockQuantityLabel(line)}
                </span>
              ) : null}
            </Detail>
            <div>
              <dt className="text-sm text-muted-foreground">Lots prélevés</dt>
              <dd className="mt-1">
                <LotsCell line={line} />
              </dd>
            </div>
          </dl>

          <p className="mt-3 border-t pt-3 text-right text-sm font-semibold tabular-nums text-foreground">
            {line.cost_total ? formatMoney(line.cost_total, currency) : "—"}
          </p>
        </li>
      ))}
    </ul>
  );
}

function ValidateDialog({
  exit,
  open,
  onOpenChange,
}: {
  exit: StockExit;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const router = useRouter();
  const mutations = useStockExitMutations();
  const [error, setError] = useState<string | null>(null);

  const count = exit.lines?.length ?? exit.lines_count ?? 0;

  async function confirm() {
    setError(null);

    try {
      const validated = await mutations.validate.mutateAsync(exit.id);

      toast.success(`Sortie ${validated.number} validée : le stock est à jour.`);
      onOpenChange(false);
      router.replace(`/stock-exits/${exit.id}`);
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
      title="Valider cette sortie ?"
      description="Le stock sera prélevé immédiatement, sur les lots qui expirent en premier sauf lot imposé. Une sortie validée ne se modifie plus : en cas d'erreur, elle devra être annulée."
      confirmLabel="Valider la sortie"
      pending={mutations.validate.isPending}
      onConfirm={() => void confirm()}
      error={error}
    >
      <dl className="space-y-2 rounded-lg bg-accent/60 p-4 text-sm">
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Motif</dt>
          <dd className="font-medium text-foreground">{exit.reason_label}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Entrepôt</dt>
          <dd className="font-medium text-foreground">{exit.warehouse.name}</dd>
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
  exit,
  open,
  onOpenChange,
}: {
  exit: StockExit;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const mutations = useStockExitMutations();
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
      await mutations.cancel.mutateAsync({ id: exit.id, reason });
      toast.success("Sortie annulée : les quantités sont revenues dans leurs lots.");
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
      title="Annuler cette sortie ?"
      description="Les quantités retournent dans les lots d'où elles ont été prélevées. La sortie et son historique sont conservés."
      confirmLabel="Annuler la sortie"
      destructive
      pending={mutations.cancel.isPending}
      onConfirm={() => void confirm()}
      error={error}
    >
      <Field label="Pourquoi annulez-vous cette sortie ?" error={fieldError}>
        <Textarea
          value={reason}
          placeholder="Erreur de saisie, marchandise retrouvée…"
          onChange={(event) => {
            setReason(event.target.value);
            setFieldError(undefined);
          }}
        />
      </Field>
    </ConfirmDialog>
  );
}

export function ExitDetail({ id, openValidation }: { id: string; openValidation: boolean }) {
  const router = useRouter();
  const { tenant } = useActiveMembership();
  const query = useStockExit(id);
  const mutations = useStockExitMutations();

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
        icon={PackageMinus}
        title="Sortie introuvable"
        description="Cette sortie n'existe pas ou a été supprimée."
        action={
          <Link href="/stock-exits" className={buttonVariants({ variant: "secondary" })}>
            Retour aux sorties
          </Link>
        }
      />
    ) : (
      <div className="space-y-4">
        <Alert variant="danger" title="Impossible de charger la sortie">
          {errorMessage(query.error)}
        </Alert>
        <Button variant="secondary" onClick={() => void query.refetch()}>
          Réessayer
        </Button>
      </div>
    );
  }

  const exit = query.data;
  const lines = exit.lines ?? [];

  async function remove() {
    try {
      await mutations.remove.mutateAsync(exit.id);
      toast.success("Brouillon supprimé.");
      router.replace("/stock-exits");
    } catch (error) {
      toast.error(errorMessage(error));
      setDeleteOpen(false);
    }
  }

  return (
    <div className="space-y-8">
      <Link
        href="/stock-exits"
        className="inline-flex items-center gap-2 text-sm text-muted-foreground transition-colors duration-200 ease-smooth hover:text-foreground"
      >
        <ArrowLeft className="size-4" strokeWidth={1.5} aria-hidden />
        Sorties de stock
      </Link>

      <header className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-2">
          <h1 className="text-3xl">{exit.number ?? "Brouillon"}</h1>
          <div className="flex flex-wrap items-center gap-2">
            <EntryStatusBadge status={exit.status} label={exit.status_label} />
            <Badge>{exit.reason_label}</Badge>
            <span className="text-sm text-muted-foreground">{formatDate(exit.exit_date)}</span>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          {exit.can.validate ? <Button onClick={() => setValidateOpen(true)}>Valider la sortie</Button> : null}

          {exit.can.update ? (
            <Link href={`/stock-exits/${exit.id}/edit`} className={buttonVariants({ variant: "secondary" })}>
              <span className="inline-flex items-center gap-2">
                <Pencil className="size-4" strokeWidth={1.5} aria-hidden />
                Modifier
              </span>
            </Link>
          ) : null}

          {exit.can.cancel ? (
            <Button variant="secondary" onClick={() => setCancelOpen(true)}>
              <Undo2 strokeWidth={1.5} aria-hidden />
              Annuler la sortie
            </Button>
          ) : null}

          {exit.can.delete ? (
            <Button variant="destructive" onClick={() => setDeleteOpen(true)}>
              <Trash2 strokeWidth={1.5} aria-hidden />
              Supprimer
            </Button>
          ) : null}
        </div>
      </header>

      {exit.status === "draft" ? (
        <Alert variant="warning" title="Brouillon">
          Cette sortie n&apos;a pas encore d&apos;effet sur le stock. Validez-la quand elle est correcte : les lots
          seront alors prélevés.
        </Alert>
      ) : null}

      {exit.status === "cancelled" ? (
        <Alert variant="danger" title="Sortie annulée">
          Annulée {exit.cancelled_at ? `le ${formatDateTime(exit.cancelled_at)}` : ""}
          {exit.cancelled_by ? ` par ${exit.cancelled_by}` : ""}. Motif : {exit.cancel_reason}. Les quantités sont
          revenues dans leurs lots.
        </Alert>
      ) : null}

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Sortie</h2>

        <dl className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <Detail label="Entrepôt d'origine">{exit.warehouse.name}</Detail>
          {exit.supplier ? <Detail label="Fournisseur">{exit.supplier.name}</Detail> : null}
          <Detail label="Référence">{exit.reference ?? "—"}</Detail>
          <Detail label="Saisie par">{exit.created_by ?? "—"}</Detail>
          {exit.validated_at ? (
            <Detail label="Validée">
              {formatDateTime(exit.validated_at)}
              {exit.validated_by ? ` · ${exit.validated_by}` : ""}
            </Detail>
          ) : null}
        </dl>

        {exit.notes ? <p className="mt-6 border-t pt-5 text-sm text-muted-foreground">{exit.notes}</p> : null}
      </section>

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Articles sortis</h2>

        <div className="mt-5">
          <LinesTable lines={lines} currency={tenant.currency} />
          <LinesCards lines={lines} currency={tenant.currency} />
        </div>

        <div className="mt-5 flex items-center justify-between gap-4 border-t pt-5">
          <p className="text-sm text-muted-foreground">
            {lines.length} {lines.length > 1 ? "lignes" : "ligne"}
          </p>
          <p className="text-right text-sm text-muted-foreground">
            Coût total
            <span className="block text-2xl font-semibold tabular-nums text-foreground">
              {exit.total_cost ? formatMoney(exit.total_cost, tenant.currency) : "—"}
            </span>
          </p>
        </div>
      </section>

      <ValidateDialog exit={exit} open={validateOpen && exit.can.validate} onOpenChange={setValidateOpen} />

      <CancelDialog exit={exit} open={cancelOpen} onOpenChange={setCancelOpen} />

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