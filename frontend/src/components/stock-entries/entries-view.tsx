"use client";

import { PackagePlus, Plus, SearchX } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";
import { PageHeader } from "@/components/app/page-header";
import { EntryActions } from "@/components/stock-entries/entry-actions";
import { EntryStatusBadge } from "@/components/stock-entries/entry-status-badge";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { Input } from "@/components/ui/input";
import { Pagination } from "@/components/ui/pagination";
import { SearchInput } from "@/components/ui/search-input";
import { SegmentedControl } from "@/components/ui/segmented-control";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { useStockEntries, useStockEntryMutations, useStockMeta } from "@/hooks/use-stock-entries";
import { useWarehouses } from "@/hooks/use-warehouses";
import { ApiError } from "@/lib/api/client";
import type { StockEntry, StockEntryFilters } from "@/lib/api/stock-entries";
import { formatDate, formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";

const ALL = "__all__";

const STATUS_OPTIONS = [
  { value: "all", label: "Toutes" },
  { value: "draft", label: "Brouillons" },
  { value: "validated", label: "Validées" },
  { value: "cancelled", label: "Annulées" },
] as const;

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

function ListSkeleton() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 5 }).map((_, index) => (
        <Skeleton key={index} className="h-16 w-full rounded-xl" />
      ))}
    </div>
  );
}

type ListProps = {
  entries: StockEntry[];
  currency: string;
  busy: boolean;
  onDelete: (entry: StockEntry) => void;
};

function EntryNumber({ entry }: { entry: StockEntry }) {
  return (
    <Link
      href={`/stock-entries/${entry.id}`}
      className="font-medium text-foreground underline-offset-4 hover:underline"
    >
      {entry.number ?? "Brouillon"}
    </Link>
  );
}

function EntryTable({ entries, currency, busy, onDelete }: ListProps) {
  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Entrée</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Fournisseur</TableHead>
            <TableHead className="hidden lg:table-cell">Entrepôt</TableHead>
            <TableHead className="hidden xl:table-cell text-right">Lignes</TableHead>
            <TableHead className="text-right">Total</TableHead>
            <TableHead>Statut</TableHead>
            <TableHead className="w-16">
              <span className="sr-only">Actions</span>
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {entries.map((entry) => (
            <TableRow key={entry.id}>
              <TableCell>
                <EntryNumber entry={entry} />
                {entry.reference ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">Bon {entry.reference}</p>
                ) : null}
              </TableCell>

              <TableCell className="text-muted-foreground">{formatDate(entry.entry_date)}</TableCell>

              <TableCell>
                <p className="text-foreground">{entry.supplier?.name ?? "—"}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">{entry.reason_label}</p>
              </TableCell>

              <TableCell className="hidden text-muted-foreground lg:table-cell">
                {entry.warehouse.name}
              </TableCell>

              <TableCell className="hidden text-right tabular-nums text-muted-foreground xl:table-cell">
                {entry.lines_count ?? "—"}
              </TableCell>

              <TableCell className="text-right font-medium tabular-nums text-foreground">
                {entry.total_amount ? formatMoney(entry.total_amount, currency) : "—"}
              </TableCell>

              <TableCell>
                <EntryStatusBadge status={entry.status} label={entry.status_label} />
              </TableCell>

              <TableCell className="text-right">
                <EntryActions entry={entry} busy={busy} onDelete={onDelete} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function EntryCards({ entries, currency, busy, onDelete }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {entries.map((entry) => (
        <li key={entry.id} className="rounded-xl border bg-card p-4 shadow-card">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 space-y-1">
              <EntryNumber entry={entry} />
              <p className="text-xs text-muted-foreground">
                {formatDate(entry.entry_date)} · {entry.reason_label}
              </p>
            </div>

            <EntryActions entry={entry} busy={busy} onDelete={onDelete} />
          </div>

          <div className="mt-3 space-y-1 text-sm">
            <p className="text-foreground">{entry.supplier?.name ?? "Sans fournisseur"}</p>
            <p className="text-muted-foreground">
              {entry.warehouse.name}
              {entry.lines_count !== undefined
                ? ` · ${entry.lines_count} ${entry.lines_count > 1 ? "lignes" : "ligne"}`
                : ""}
            </p>
          </div>

          <div className="mt-3 flex items-center justify-between gap-3">
            <p className="font-medium tabular-nums text-foreground">
              {entry.total_amount ? formatMoney(entry.total_amount, currency) : "—"}
            </p>
            <EntryStatusBadge status={entry.status} label={entry.status_label} />
          </div>
        </li>
      ))}
    </ul>
  );
}

export function EntriesView() {
  const { tenant } = useActiveMembership();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<StockEntryFilters["status"]>("all");
  const [reason, setReason] = useState<string>(ALL);
  const [warehouseId, setWarehouseId] = useState<string>(ALL);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [toDelete, setToDelete] = useState<StockEntry | null>(null);

  const debouncedSearch = useDebouncedValue(search, 300);

  const filters: StockEntryFilters = {
    search: debouncedSearch.trim(),
    status,
    reason: reason === ALL ? "all" : (reason as StockEntryFilters["reason"]),
    warehouseId: warehouseId === ALL ? null : warehouseId,
    from,
    to,
    page,
  };

  const hasFilters =
    filters.search !== "" ||
    status !== "all" ||
    reason !== ALL ||
    warehouseId !== ALL ||
    from !== "" ||
    to !== "";

  const query = useStockEntries(filters);
  const meta = useStockMeta();
  const warehouses = useWarehouses({ search: "", status: "all" });
  const mutations = useStockEntryMutations();

  const entries = query.data?.data ?? [];
  const canCreate = query.data?.meta.can.create ?? false;

  const reasonOptions = [
    { value: ALL, label: "Tous les motifs" },
    ...(meta.data?.entry_reasons ?? []).map((item) => ({ value: item.value, label: item.label })),
  ];

  const warehouseOptions = [
    { value: ALL, label: "Tous les entrepôts" },
    ...(warehouses.data?.data ?? []).map((item) => ({ value: item.id, label: item.name })),
  ];

  function resetPage<T>(setter: (value: T) => void) {
    return (value: T) => {
      setter(value);
      setPage(1);
    };
  }

  function resetFilters() {
    setSearch("");
    setStatus("all");
    setReason(ALL);
    setWarehouseId(ALL);
    setFrom("");
    setTo("");
    setPage(1);
  }

  async function confirmDelete() {
    if (!toDelete) return;

    const target = toDelete;
    const wasLastOnPage = entries.length === 1 && page > 1;

    try {
      await mutations.remove.mutateAsync(target.id);
      toast.success("Brouillon supprimé.");

      if (wasLastOnPage) {
        setPage(page - 1);
      }
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setToDelete(null);
    }
  }

  const createButton = canCreate ? (
    <Link href="/stock-entries/new" className={buttonVariants()}>
      <span className="inline-flex items-center gap-2">
        <Plus className="size-4" strokeWidth={1.5} aria-hidden />
        Nouvelle entrée
      </span>
    </Link>
  ) : null;

  return (
    <div className="space-y-8">
      <PageHeader
        title="Entrées de stock"
        description="Chaque livraison reçue : fournisseur, quantités, prix d'achat et dates d'expiration."
        actions={createButton}
      />

      <div className="space-y-3">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <SearchInput
            size="lg"
            aria-label="Rechercher une entrée"
            placeholder="N° d'entrée, bon ou fournisseur"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
            className="lg:w-80"
          />

          <SegmentedControl
            label="Filtrer par statut"
            options={STATUS_OPTIONS}
            value={status}
            onChange={resetPage(setStatus)}
            className="self-start"
          />
        </div>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Select
            size="lg"
            options={reasonOptions}
            value={reason}
            onValueChange={resetPage(setReason)}
          />
          <Select
            size="lg"
            options={warehouseOptions}
            value={warehouseId}
            onValueChange={resetPage(setWarehouseId)}
          />
          <Input
            size="lg"
            type="date"
            aria-label="Du"
            value={from}
            max={to || undefined}
            onChange={(event) => resetPage(setFrom)(event.target.value)}
          />
          <Input
            size="lg"
            type="date"
            aria-label="Au"
            value={to}
            min={from || undefined}
            onChange={(event) => resetPage(setTo)(event.target.value)}
          />
        </div>
      </div>

      {query.isPending ? (
        <ListSkeleton />
      ) : query.isError && !query.data ? (
        <div className="space-y-4">
          <Alert variant="danger" title="Impossible de charger les entrées">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : entries.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucune entrée ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={PackagePlus}
            title="Aucune entrée"
            description="Enregistrez votre première livraison : le stock de vos articles se met à jour à la validation."
            action={createButton}
          />
        )
      ) : (
        <div className="space-y-5">
          <div
            className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}
          >
            <EntryTable
              entries={entries}
              currency={tenant.currency}
              busy={mutations.remove.isPending}
              onDelete={setToDelete}
            />
            <EntryCards
              entries={entries}
              currency={tenant.currency}
              busy={mutations.remove.isPending}
              onDelete={setToDelete}
            />
          </div>

          {query.data ? (
            <Pagination
              meta={query.data.meta}
              itemLabel={{ singular: "entrée", plural: "entrées" }}
              onPageChange={setPage}
            />
          ) : null}
        </div>
      )}

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(open) => {
          if (!open && !mutations.remove.isPending) setToDelete(null);
        }}
        title="Supprimer ce brouillon ?"
        description="Ce brouillon sera supprimé définitivement. Le stock n'est pas concerné : il n'a pas encore été validé."
        confirmLabel="Supprimer"
        destructive
        pending={mutations.remove.isPending}
        onConfirm={() => void confirmDelete()}
      />
    </div>
  );
}