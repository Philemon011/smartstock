"use client";

import { Mail, MapPin, Phone, Plus, SearchX, Users } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { PageHeader } from "@/components/app/page-header";
import { CustomerActions, type CustomerHandlers } from "@/components/customers/customer-actions";
import { CustomerSheet } from "@/components/customers/customer-sheet";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { Pagination } from "@/components/ui/pagination";
import { SearchInput } from "@/components/ui/search-input";
import { SegmentedControl } from "@/components/ui/segmented-control";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useCustomerMutations, useCustomers } from "@/hooks/use-customers";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { ApiError } from "@/lib/api/client";
import type { Customer, CustomerStatusFilter } from "@/lib/api/customers";
import { formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";

const STATUS_OPTIONS = [
  { value: "all", label: "Tous" },
  { value: "active", label: "Actifs" },
  { value: "inactive", label: "Désactivés" },
] as const;

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

function limitLabel(customer: Customer, currency: string): string {
  if (customer.credit_limit === null) return "Sans plafond";

  return /^0+(\.0+)?$/.test(customer.credit_limit)
    ? "Aucun crédit"
    : formatMoney(customer.credit_limit, currency);
}

function StatusBadge({ customer }: { customer: Customer }) {
  return customer.is_active ? (
    <Badge variant="success">Actif</Badge>
  ) : (
    <Badge variant="neutral">Désactivé</Badge>
  );
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
  customers: Customer[];
  currency: string;
  busy: boolean;
  handlers: CustomerHandlers;
};

function CustomerTable({ customers, currency, busy, handlers }: ListProps) {
  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Client</TableHead>
            <TableHead>Coordonnées</TableHead>
            <TableHead className="hidden lg:table-cell">Plafond de crédit</TableHead>
            <TableHead>Statut</TableHead>
            <TableHead className="w-16">
              <span className="sr-only">Actions</span>
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {customers.map((customer) => (
            <TableRow key={customer.id}>
              <TableCell>
                <p className="font-medium text-foreground">{customer.name}</p>
                {customer.city ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">{customer.city}</p>
                ) : null}
              </TableCell>

              <TableCell>
                <p className="text-foreground">{customer.phone ?? "—"}</p>
                {customer.email ? (
                  <p className="mt-0.5 max-w-56 truncate text-xs text-muted-foreground">{customer.email}</p>
                ) : null}
              </TableCell>

              <TableCell className="hidden tabular-nums text-muted-foreground lg:table-cell">
                {limitLabel(customer, currency)}
              </TableCell>

              <TableCell>
                <StatusBadge customer={customer} />
              </TableCell>

              <TableCell className="text-right">
                <CustomerActions customer={customer} busy={busy} handlers={handlers} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function CustomerCards({ customers, currency, busy, handlers }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {customers.map((customer) => (
        <li key={customer.id} className="rounded-xl border bg-card p-4 shadow-card">
          <div className="flex items-start justify-between gap-3">
            <p className="min-w-0 font-medium text-foreground">{customer.name}</p>

            <CustomerActions customer={customer} busy={busy} handlers={handlers} />
          </div>

          <div className="mt-3 space-y-2 text-sm text-muted-foreground">
            {customer.phone ? (
              <a
                href={`tel:${customer.phone.replace(/\s+/g, "")}`}
                className="flex items-center gap-2 text-foreground"
              >
                <Phone className="size-4 shrink-0 text-muted-foreground" strokeWidth={1.5} aria-hidden />
                {customer.phone}
              </a>
            ) : null}

            {customer.email ? (
              <a href={`mailto:${customer.email}`} className="flex items-center gap-2">
                <Mail className="size-4 shrink-0" strokeWidth={1.5} aria-hidden />
                <span className="truncate">{customer.email}</span>
              </a>
            ) : null}

            {customer.city ? (
              <p className="flex items-center gap-2">
                <MapPin className="size-4 shrink-0" strokeWidth={1.5} aria-hidden />
                {customer.city}
              </p>
            ) : null}
          </div>

          <div className="mt-3 flex items-center justify-between gap-3">
            <StatusBadge customer={customer} />
            <p className="text-xs text-muted-foreground">Crédit : {limitLabel(customer, currency)}</p>
          </div>
        </li>
      ))}
    </ul>
  );
}

export function CustomersView() {
  const { tenant } = useActiveMembership();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<CustomerStatusFilter>("all");
  const [page, setPage] = useState(1);
  const [sheet, setSheet] = useState<{ open: boolean; customer: Customer | null }>({
    open: false,
    customer: null,
  });
  const [toDelete, setToDelete] = useState<Customer | null>(null);

  const debouncedSearch = useDebouncedValue(search, 300);
  const filters = { search: debouncedSearch.trim(), status, page };
  const hasFilters = filters.search !== "" || status !== "all";

  const query = useCustomers(filters);
  const mutations = useCustomerMutations();

  const busy =
    mutations.activate.isPending || mutations.deactivate.isPending || mutations.remove.isPending;

  const customers = query.data?.data ?? [];
  const canCreate = query.data?.meta.can.create ?? false;

  async function run(action: () => Promise<unknown>, success: string) {
    try {
      await action();
      toast.success(success);
    } catch (error) {
      toast.error(errorMessage(error));
    }
  }

  async function confirmDelete() {
    if (!toDelete) return;

    const target = toDelete;
    const wasLastOnPage = customers.length === 1 && page > 1;

    try {
      await mutations.remove.mutateAsync(target.id);
      toast.success("Client supprimé.");

      if (wasLastOnPage) {
        setPage(page - 1);
      }
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setToDelete(null);
    }
  }

  const handlers: CustomerHandlers = {
    onEdit: (customer) => setSheet({ open: true, customer }),
    onActivate: (customer) =>
      void run(() => mutations.activate.mutateAsync(customer.id), "Client réactivé."),
    onDeactivate: (customer) =>
      void run(() => mutations.deactivate.mutateAsync(customer.id), "Client désactivé."),
    onDelete: (customer) => setToDelete(customer),
  };

  const createButton = canCreate ? (
    <Button onClick={() => setSheet({ open: true, customer: null })}>
      <Plus strokeWidth={1.5} aria-hidden />
      Nouveau client
    </Button>
  ) : null;

  function changeSearch(value: string) {
    setSearch(value);
    setPage(1);
  }

  function changeStatus(value: CustomerStatusFilter) {
    setStatus(value);
    setPage(1);
  }

  function resetFilters() {
    setSearch("");
    setStatus("all");
    setPage(1);
  }

  return (
    <div className="space-y-8">
      <PageHeader
        title="Clients"
        description="Ceux à qui vous vendez : retrouvez-les au moment d'enregistrer une vente et suivez ce qu'ils vous doivent."
        actions={createButton}
      />

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <SearchInput
          size="lg"
          aria-label="Rechercher un client"
          placeholder="Rechercher par nom, téléphone, email ou ville"
          value={search}
          onChange={(event) => changeSearch(event.target.value)}
          className="sm:w-96"
        />

        <SegmentedControl
          label="Filtrer par statut"
          options={STATUS_OPTIONS}
          value={status}
          onChange={changeStatus}
          className="self-start"
        />
      </div>

      {query.isPending ? (
        <ListSkeleton />
      ) : query.isError && !query.data ? (
        <div className="space-y-4">
          <Alert variant="danger" title="Impossible de charger les clients">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : customers.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucun client ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Users}
            title="Aucun client"
            description="Ajoutez vos clients pour leur vendre à crédit et suivre leurs paiements."
            action={createButton}
          />
        )
      ) : (
        <div className="space-y-5">
          <div className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}>
            <CustomerTable
              customers={customers}
              currency={tenant.currency}
              busy={busy}
              handlers={handlers}
            />
            <CustomerCards
              customers={customers}
              currency={tenant.currency}
              busy={busy}
              handlers={handlers}
            />
          </div>

          {query.data ? (
            <Pagination
              meta={query.data.meta}
              itemLabel={{ singular: "client", plural: "clients" }}
              onPageChange={setPage}
            />
          ) : null}
        </div>
      )}

      <CustomerSheet
        open={sheet.open}
        customer={sheet.customer}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(open) => {
          if (!open && !mutations.remove.isPending) setToDelete(null);
        }}
        title="Supprimer ce client ?"
        description={
          toDelete
            ? `« ${toDelete.name} » sera supprimé définitivement. Cette action est irréversible. Pour garder son historique, désactivez-le plutôt.`
            : ""
        }
        confirmLabel="Supprimer"
        destructive
        pending={mutations.remove.isPending}
        onConfirm={() => void confirmDelete()}
      />
    </div>
  );
}