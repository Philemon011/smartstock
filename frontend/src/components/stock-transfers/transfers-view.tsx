"use client";

import { ArrowLeftRight, ArrowRight, Plus, SearchX } from "lucide-react";
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
import { useStockTransfers } from "@/hooks/use-stock-transfers";
import { useWarehouses } from "@/hooks/use-warehouses";
import { ApiError } from "@/lib/api/client";
import type { StockTransfer, StockTransferFilters } from "@/lib/api/stock-transfers";
import { formatDate, formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";

const ALL = "__all__";

const STATUS_OPTIONS = [
  { value: "all", label: "Tous" },
  { value: "draft", label: "Brouillons" },
  { value: "validated", label: "Validés" },
  { value: "cancelled", label: "Annulés" },
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

function TransferNumber({ transfer }: { transfer: StockTransfer }) {
  return (
    <Link
      href={`/stock-transfers/${transfer.id}`}
      className="font-medium text-foreground underline-offset-4 hover:underline"
    >
      {transfer.number ?? "Brouillon"}
    </Link>
  );
}

function Route({ transfer }: { transfer: StockTransfer }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-0.5">
      <span>{transfer.from_warehouse.name}</span>
      <ArrowRight className="size-3.5 shrink-0 text-muted-foreground" strokeWidth={1.5} aria-hidden />
      <span>{transfer.to_warehouse.name}</span>
    </span>
  );
}

type ListProps = { transfers: StockTransfer[]; currency: string };

function TransferTable({ transfers, currency }: ListProps) {
  const showCost = transfers.some((transfer) => transfer.total_cost !== undefined);

  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Transfert</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Trajet</TableHead>
            <TableHead className="hidden xl:table-cell text-right">Lignes</TableHead>
            {showCost ? <TableHead className="text-right">Valeur</TableHead> : null}
            <TableHead>Statut</TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {transfers.map((transfer) => (
            <TableRow key={transfer.id}>
              <TableCell>
                <TransferNumber transfer={transfer} />
                {transfer.reference ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">Réf. {transfer.reference}</p>
                ) : null}
              </TableCell>

              <TableCell className="text-muted-foreground">{formatDate(transfer.transfer_date)}</TableCell>

              <TableCell className="text-foreground">
                <Route transfer={transfer} />
              </TableCell>

              <TableCell className="hidden text-right tabular-nums text-muted-foreground xl:table-cell">
                {transfer.lines_count ?? "—"}
              </TableCell>

              {showCost ? (
                <TableCell className="text-right font-medium tabular-nums text-foreground">
                  {transfer.total_cost ? formatMoney(transfer.total_cost, currency) : "—"}
                </TableCell>
              ) : null}

              <TableCell>
                <EntryStatusBadge status={transfer.status} label={transfer.status_label} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function TransferCards({ transfers, currency }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {transfers.map((transfer) => (
        <li key={transfer.id} className="rounded-xl border bg-card p-4 shadow-card">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 space-y-1">
              <TransferNumber transfer={transfer} />
              <p className="text-xs text-muted-foreground">{formatDate(transfer.transfer_date)}</p>
            </div>

            <EntryStatusBadge status={transfer.status} label={transfer.status_label} />
          </div>

          <p className="mt-3 text-sm text-foreground">
            <Route transfer={transfer} />
          </p>

          <div className="mt-3 flex items-center justify-between gap-3 text-sm">
            <p className="text-muted-foreground">
              {transfer.lines_count !== undefined
                ? `${transfer.lines_count} ${transfer.lines_count > 1 ? "lignes" : "ligne"}`
                : ""}
            </p>
            {transfer.total_cost ? (
              <p className="font-medium tabular-nums text-foreground">{formatMoney(transfer.total_cost, currency)}</p>
            ) : null}
          </div>
        </li>
      ))}
    </ul>
  );
}

export function TransfersView() {
  const { tenant } = useActiveMembership();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<StockTransferFilters["status"]>("all");
  const [warehouseId, setWarehouseId] = useState<string>(ALL);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);

  const debouncedSearch = useDebouncedValue(search, 300);

  const filters: StockTransferFilters = {
    search: debouncedSearch.trim(),
    status,
    warehouseId: warehouseId === ALL ? null : warehouseId,
    from,
    to,
    page,
  };

  const hasFilters =
    filters.search !== "" || status !== "all" || warehouseId !== ALL || from !== "" || to !== "";

  const query = useStockTransfers(filters);
  const warehouses = useWarehouses({ search: "", status: "all" });

  const transfers = query.data?.data ?? [];
  const canCreate = query.data?.meta.can.create ?? false;

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
    setWarehouseId(ALL);
    setFrom("");
    setTo("");
    setPage(1);
  }

  const createButton = canCreate ? (
    <Link href="/stock-transfers/new" className={buttonVariants()}>
      <span className="inline-flex items-center gap-2">
        <Plus className="size-4" strokeWidth={1.5} aria-hidden />
        Nouveau transfert
      </span>
    </Link>
  ) : null;

  return (
    <div className="space-y-8">
      <PageHeader
        title="Transferts de stock"
        description="Déplacez de la marchandise d'un entrepôt à l'autre : les lots, leurs expirations et leur coût suivent."
        actions={createButton}
      />

      <div className="space-y-3">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <SearchInput
            size="lg"
            aria-label="Rechercher un transfert"
            placeholder="N° de transfert ou référence"
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

        <div className="grid gap-3 sm:grid-cols-3">
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
          <Alert variant="danger" title="Impossible de charger les transferts">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : transfers.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucun transfert ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={ArrowLeftRight}
            title="Aucun transfert"
            description="Déplacez du stock d'un entrepôt à l'autre : il arrive tout de suite à destination, avec le même coût."
            action={createButton}
          />
        )
      ) : (
        <div className="space-y-5">
          <div className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}>
            <TransferTable transfers={transfers} currency={tenant.currency} />
            <TransferCards transfers={transfers} currency={tenant.currency} />
          </div>

          {query.data ? (
            <Pagination
              meta={query.data.meta}
              itemLabel={{ singular: "transfert", plural: "transferts" }}
              onPageChange={setPage}
            />
          ) : null}
        </div>
      )}
    </div>
  );
}