"use client";

import { PackageMinus, Plus, SearchX } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { PageHeader } from "@/components/app/page-header";
import { useActiveMembership } from "@/components/session/session-provider";
import { EntryStatusBadge } from "@/components/stock-entries/entry-status-badge";
import { Alert } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
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
import { useStockMeta } from "@/hooks/use-stock-entries";
import { useStockExits } from "@/hooks/use-stock-exits";
import { useWarehouses } from "@/hooks/use-warehouses";
import { ApiError } from "@/lib/api/client";
import type { StockExit, StockExitFilters } from "@/lib/api/stock-exits";
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
  exits: StockExit[];
  currency: string;
};

function ExitNumber({ exit }: { exit: StockExit }) {
  return (
    <Link
      href={`/stock-exits/${exit.id}`}
      className="font-medium text-foreground underline-offset-4 hover:underline"
    >
      {exit.number ?? "Brouillon"}
    </Link>
  );
}

function ExitTable({ exits, currency }: ListProps) {
  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Sortie</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Motif</TableHead>
            <TableHead className="hidden lg:table-cell">Entrepôt</TableHead>
            <TableHead className="hidden xl:table-cell text-right">Lignes</TableHead>
            <TableHead className="text-right">Coût</TableHead>
            <TableHead>Statut</TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {exits.map((exit) => (
            <TableRow key={exit.id}>
              <TableCell>
                <ExitNumber exit={exit} />
                {exit.reference ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">Réf. {exit.reference}</p>
                ) : null}
              </TableCell>

              <TableCell className="text-muted-foreground">{formatDate(exit.exit_date)}</TableCell>

              <TableCell>
                <p className="text-foreground">{exit.reason_label}</p>
                {exit.supplier ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">{exit.supplier.name}</p>
                ) : null}
              </TableCell>

              <TableCell className="hidden text-muted-foreground lg:table-cell">
                {exit.warehouse.name}
              </TableCell>

              <TableCell className="hidden text-right tabular-nums text-muted-foreground xl:table-cell">
                {exit.lines_count ?? "—"}
              </TableCell>

              <TableCell className="text-right font-medium tabular-nums text-foreground">
                {exit.total_cost ? formatMoney(exit.total_cost, currency) : "—"}
              </TableCell>

              <TableCell>
                <EntryStatusBadge status={exit.status} label={exit.status_label} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function ExitCards({ exits, currency }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {exits.map((exit) => (
        <li key={exit.id} className="rounded-xl border bg-card p-4 shadow-card">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 space-y-1">
              <ExitNumber exit={exit} />
              <p className="text-xs text-muted-foreground">
                {formatDate(exit.exit_date)} · {exit.reason_label}
              </p>
            </div>

            <EntryStatusBadge status={exit.status} label={exit.status_label} />
          </div>

          <div className="mt-3 space-y-1 text-sm">
            {exit.supplier ? <p className="text-foreground">{exit.supplier.name}</p> : null}
            <p className="text-muted-foreground">
              {exit.warehouse.name}
              {exit.lines_count !== undefined
                ? ` · ${exit.lines_count} ${exit.lines_count > 1 ? "lignes" : "ligne"}`
                : ""}
            </p>
          </div>

          <p className="mt-3 font-medium tabular-nums text-foreground">
            {exit.total_cost ? formatMoney(exit.total_cost, currency) : "—"}
          </p>
        </li>
      ))}
    </ul>
  );
}

export function ExitsView() {
  const { tenant, abilities } = useActiveMembership();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<StockExitFilters["status"]>("all");
  const [reason, setReason] = useState<string>(ALL);
  const [warehouseId, setWarehouseId] = useState<string>(ALL);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);

  const debouncedSearch = useDebouncedValue(search, 300);

  const filters: StockExitFilters = {
    search: debouncedSearch.trim(),
    status,
    reason: reason === ALL ? "all" : (reason as StockExitFilters["reason"]),
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

  const query = useStockExits(filters);
  const meta = useStockMeta();
  const warehouses = useWarehouses({ search: "", status: "all" });

  const exits = query.data?.data ?? [];

  const reasonOptions = [
    { value: ALL, label: "Tous les motifs" },
    ...(meta.data?.exit_reasons ?? []).map((item) => ({ value: item.value, label: item.label })),
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

  if (!abilities.manage_stock) {
    return (
      <Alert variant="danger" title="Accès réservé">
        Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les
        sorties de stock.
      </Alert>
    );
  }

  const createButton = (
    <Link href="/stock-exits/new" className={buttonVariants()}>
      <span className="inline-flex items-center gap-2">
        <Plus className="size-4" strokeWidth={1.5} aria-hidden />
        Nouvelle sortie
      </span>
    </Link>
  );

  return (
    <div className="space-y-8">
      <PageHeader
        title="Sorties de stock"
        description="Chaque sortie de marchandise : perte, casse, péremption, usage interne, don ou retour fournisseur."
        actions={createButton}
      />

      <div className="space-y-3">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <SearchInput
            size="lg"
            aria-label="Rechercher une sortie"
            placeholder="N° de sortie, référence ou fournisseur"
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
          <Alert variant="danger" title="Impossible de charger les sorties">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : exits.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucune sortie ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={PackageMinus}
            title="Aucune sortie"
            description="Enregistrez une perte, une casse ou un retour fournisseur : le stock se met à jour à la validation."
            action={createButton}
          />
        )
      ) : (
        <div className="space-y-5">
          <div
            className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}
          >
            <ExitTable exits={exits} currency={tenant.currency} />
            <ExitCards exits={exits} currency={tenant.currency} />
          </div>

          {query.data ? (
            <Pagination
              meta={query.data.meta}
              itemLabel={{ singular: "sortie", plural: "sorties" }}
              onPageChange={setPage}
            />
          ) : null}
        </div>
      )}
    </div>
  );
}