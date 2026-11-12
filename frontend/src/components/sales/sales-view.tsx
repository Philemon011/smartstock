"use client";

import { Plus, Receipt, SearchX } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { PageHeader } from "@/components/app/page-header";
import { SaleStatusBadge } from "@/components/sales/payment-status-badge";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { Input } from "@/components/ui/input";
import { Pagination } from "@/components/ui/pagination";
import { RemoteCombobox } from "@/components/ui/remote-combobox";
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
import { useSales } from "@/hooks/use-sales";
import { useWarehouses } from "@/hooks/use-warehouses";
import { ApiError } from "@/lib/api/client";
import { listCustomers, type Customer } from "@/lib/api/customers";
import type { Sale, SaleFilters } from "@/lib/api/sales";
import { formatDate, formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";

const ALL = "__all__";

const VIEW_OPTIONS = [
  { value: "all", label: "Toutes" },
  { value: "paid", label: "Payées" },
  { value: "due", label: "Reste à payer" },
  { value: "cancelled", label: "Annulées" },
] as const;

type View = (typeof VIEW_OPTIONS)[number]["value"];

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

function hasBalance(sale: Sale): boolean {
  return sale.status === "validated" && /[1-9]/.test(sale.balance_due);
}

type ListProps = { sales: Sale[]; currency: string };

function SaleNumber({ sale }: { sale: Sale }) {
  return (
    <Link
      href={`/sales/${sale.id}`}
      className="font-medium text-foreground underline-offset-4 hover:underline"
    >
      {sale.number}
    </Link>
  );
}

function SaleTable({ sales, currency }: ListProps) {
  const showMargin = sales.some((sale) => sale.margin !== undefined);

  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Vente</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Client</TableHead>
            <TableHead className="hidden lg:table-cell">Entrepôt</TableHead>
            <TableHead className="text-right">Total</TableHead>
            <TableHead className="text-right">Reste dû</TableHead>
            {showMargin ? <TableHead className="hidden text-right xl:table-cell">Marge</TableHead> : null}
            <TableHead>Statut</TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {sales.map((sale) => (
            <TableRow key={sale.id}>
              <TableCell>
                <SaleNumber sale={sale} />
                {sale.lines_count !== undefined ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {sale.lines_count} {sale.lines_count > 1 ? "lignes" : "ligne"}
                  </p>
                ) : null}
              </TableCell>

              <TableCell className="text-muted-foreground">{formatDate(sale.sale_date)}</TableCell>

              <TableCell>
                <p className="text-foreground">{sale.customer?.name ?? "Client de passage"}</p>
                {sale.customer?.phone ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">{sale.customer.phone}</p>
                ) : null}
              </TableCell>

              <TableCell className="hidden text-muted-foreground lg:table-cell">
                {sale.warehouse.name}
              </TableCell>

              <TableCell className="text-right font-medium tabular-nums text-foreground">
                {formatMoney(sale.total, currency)}
              </TableCell>

              <TableCell
                className={cn(
                  "text-right tabular-nums",
                  hasBalance(sale) ? "font-medium text-warning" : "text-muted-foreground",
                )}
              >
                {hasBalance(sale) ? formatMoney(sale.balance_due, currency) : "—"}
              </TableCell>

              {showMargin ? (
                <TableCell className="hidden text-right tabular-nums text-muted-foreground xl:table-cell">
                  {sale.status === "validated" && sale.margin !== undefined
                    ? formatMoney(sale.margin, currency)
                    : "—"}
                </TableCell>
              ) : null}

              <TableCell>
                <SaleStatusBadge sale={sale} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function SaleCards({ sales, currency }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {sales.map((sale) => (
        <li key={sale.id} className="rounded-xl border bg-card p-4 shadow-card">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 space-y-1">
              <SaleNumber sale={sale} />
              <p className="text-xs text-muted-foreground">{formatDate(sale.sale_date)}</p>
            </div>

            <SaleStatusBadge sale={sale} />
          </div>

          <p className="mt-3 text-sm text-foreground">{sale.customer?.name ?? "Client de passage"}</p>

          <div className="mt-3 flex items-end justify-between gap-3">
            <p className="font-medium tabular-nums text-foreground">{formatMoney(sale.total, currency)}</p>
            {hasBalance(sale) ? (
              <p className="text-xs text-warning">Reste {formatMoney(sale.balance_due, currency)}</p>
            ) : null}
          </div>
        </li>
      ))}
    </ul>
  );
}

export function SalesView() {
  const { tenant, abilities } = useActiveMembership();

  const [search, setSearch] = useState("");
  const [view, setView] = useState<View>("all");
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [warehouseId, setWarehouseId] = useState<string>(ALL);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);

  const debouncedSearch = useDebouncedValue(search, 300);

  const filters: SaleFilters = {
    search: debouncedSearch.trim(),
    status: view === "paid" ? "validated" : view === "cancelled" ? "cancelled" : "all",
    paymentStatus: view === "paid" ? "paid" : "all",
    customerId: customer?.id ?? null,
    warehouseId: warehouseId === ALL ? null : warehouseId,
    hasBalance: view === "due",
    from,
    to,
    page,
  };

  const hasFilters =
    filters.search !== "" ||
    view !== "all" ||
    customer !== null ||
    warehouseId !== ALL ||
    from !== "" ||
    to !== "";

  const query = useSales(filters);
  const warehouses = useWarehouses({ search: "", status: "all" });

  const sales = query.data?.data ?? [];
  const canCreate = (query.data?.meta.can.create ?? false) && abilities.sell;

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
    setView("all");
    setCustomer(null);
    setWarehouseId(ALL);
    setFrom("");
    setTo("");
    setPage(1);
  }

  const createButton = canCreate ? (
    <Link href="/sales/new" className={buttonVariants()}>
      <span className="inline-flex items-center gap-2">
        <Plus className="size-4" strokeWidth={1.5} aria-hidden />
        Nouvelle vente
      </span>
    </Link>
  ) : null;

  return (
    <div className="space-y-8">
      <PageHeader
        title="Ventes"
        description="Chaque vente enregistrée : client, paiements, reste à payer et lots vendus."
        actions={createButton}
      />

      <div className="space-y-3">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <SearchInput
            size="lg"
            aria-label="Rechercher une vente"
            placeholder="N° de vente, client ou téléphone"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
            className="lg:w-80"
          />

          <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <SegmentedControl
              label="Filtrer les ventes"
              options={VIEW_OPTIONS}
              value={view}
              onChange={resetPage(setView)}
            />
          </div>
        </div>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <RemoteCombobox<Customer>
            size="lg"
            queryKey={["customers", tenant.id, "sales-filter"]}
            fetcher={(term, signal) =>
              listCustomers({ search: term, status: "all", page: 1 }, signal).then((result) => result.data)
            }
            value={customer}
            getKey={(item) => item.id}
            renderItem={(item) => <span className="truncate">{item.name}</span>}
            onChange={(item) => {
              setCustomer(item);
              setPage(1);
            }}
            onClear={() => {
              setCustomer(null);
              setPage(1);
            }}
            placeholder="Tous les clients"
            searchPlaceholder="Nom, téléphone, email ou ville"
            emptyMessage="Aucun client ne correspond."
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
          <Alert variant="danger" title="Impossible de charger les ventes">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : sales.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucune vente ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Receipt}
            title="Aucune vente"
            description="Enregistrez votre première vente : le stock et ce que doivent vos clients se mettent à jour."
            action={createButton}
          />
        )
      ) : (
        <div className="space-y-5">
          <div className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}>
            <SaleTable sales={sales} currency={tenant.currency} />
            <SaleCards sales={sales} currency={tenant.currency} />
          </div>

          {query.data ? (
            <Pagination
              meta={query.data.meta}
              itemLabel={{ singular: "vente", plural: "ventes" }}
              onPageChange={setPage}
            />
          ) : null}
        </div>
      )}
    </div>
  );
}