"use client";

import { Mail, MapPin, Phone, Plus, SearchX, Truck } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { PageHeader } from "@/components/app/page-header";
import { SupplierActions, type SupplierHandlers } from "@/components/suppliers/supplier-actions";
import { SupplierSheet } from "@/components/suppliers/supplier-sheet";
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
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { useSupplierMutations, useSuppliers } from "@/hooks/use-suppliers";
import { ApiError } from "@/lib/api/client";
import type { Supplier, SupplierStatusFilter } from "@/lib/api/suppliers";
import { cn } from "@/lib/utils";

const STATUS_OPTIONS = [
  { value: "all", label: "Tous" },
  { value: "active", label: "Actifs" },
  { value: "inactive", label: "Désactivés" },
] as const;

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

function StatusBadge({ supplier }: { supplier: Supplier }) {
  return supplier.is_active ? (
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
  suppliers: Supplier[];
  busy: boolean;
  handlers: SupplierHandlers;
};

function SupplierTable({ suppliers, busy, handlers }: ListProps) {
  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Fournisseur</TableHead>
            <TableHead>Coordonnées</TableHead>
            <TableHead className="hidden lg:table-cell">Ville</TableHead>
            <TableHead>Statut</TableHead>
            <TableHead className="w-16">
              <span className="sr-only">Actions</span>
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {suppliers.map((supplier) => (
            <TableRow key={supplier.id}>
              <TableCell>
                <p className="font-medium text-foreground">{supplier.name}</p>
                {supplier.contact_name ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">{supplier.contact_name}</p>
                ) : null}
              </TableCell>

              <TableCell>
                <p className="text-foreground">{supplier.phone ?? "—"}</p>
                {supplier.email ? (
                  <p className="mt-0.5 max-w-56 truncate text-xs text-muted-foreground">
                    {supplier.email}
                  </p>
                ) : null}
              </TableCell>

              <TableCell className="hidden text-muted-foreground lg:table-cell">
                {supplier.city ?? "—"}
              </TableCell>

              <TableCell>
                <StatusBadge supplier={supplier} />
              </TableCell>

              <TableCell className="text-right">
                <SupplierActions supplier={supplier} busy={busy} handlers={handlers} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function SupplierCards({ suppliers, busy, handlers }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {suppliers.map((supplier) => (
        <li key={supplier.id} className="rounded-xl border bg-card p-4 shadow-card">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 space-y-1">
              <p className="font-medium text-foreground">{supplier.name}</p>
              {supplier.contact_name ? (
                <p className="text-sm text-muted-foreground">{supplier.contact_name}</p>
              ) : null}
            </div>

            <SupplierActions supplier={supplier} busy={busy} handlers={handlers} />
          </div>

          <div className="mt-3 space-y-2 text-sm text-muted-foreground">
            {supplier.phone ? (
              <a
                href={`tel:${supplier.phone.replace(/\s+/g, "")}`}
                className="flex items-center gap-2 text-foreground"
              >
                <Phone className="size-4 shrink-0 text-muted-foreground" strokeWidth={1.5} aria-hidden />
                {supplier.phone}
              </a>
            ) : null}

            {supplier.email ? (
              <a href={`mailto:${supplier.email}`} className="flex items-center gap-2">
                <Mail className="size-4 shrink-0" strokeWidth={1.5} aria-hidden />
                <span className="truncate">{supplier.email}</span>
              </a>
            ) : null}

            {supplier.city ? (
              <p className="flex items-center gap-2">
                <MapPin className="size-4 shrink-0" strokeWidth={1.5} aria-hidden />
                {supplier.city}
              </p>
            ) : null}
          </div>

          <div className="mt-3">
            <StatusBadge supplier={supplier} />
          </div>
        </li>
      ))}
    </ul>
  );
}

export function SuppliersView() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<SupplierStatusFilter>("all");
  const [page, setPage] = useState(1);
  const [sheet, setSheet] = useState<{ open: boolean; supplier: Supplier | null }>({
    open: false,
    supplier: null,
  });
  const [toDelete, setToDelete] = useState<Supplier | null>(null);

  const debouncedSearch = useDebouncedValue(search, 300);
  const filters = { search: debouncedSearch.trim(), status, page };
  const hasFilters = filters.search !== "" || status !== "all";

  const query = useSuppliers(filters);
  const mutations = useSupplierMutations();

  const busy =
    mutations.activate.isPending || mutations.deactivate.isPending || mutations.remove.isPending;

  const suppliers = query.data?.data ?? [];
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
    const wasLastOnPage = suppliers.length === 1 && page > 1;

    try {
      await mutations.remove.mutateAsync(target.id);
      toast.success("Fournisseur supprimé.");

      if (wasLastOnPage) {
        setPage(page - 1);
      }
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setToDelete(null);
    }
  }

  const handlers: SupplierHandlers = {
    onEdit: (supplier) => setSheet({ open: true, supplier }),
    onActivate: (supplier) =>
      void run(() => mutations.activate.mutateAsync(supplier.id), "Fournisseur réactivé."),
    onDeactivate: (supplier) =>
      void run(() => mutations.deactivate.mutateAsync(supplier.id), "Fournisseur désactivé."),
    onDelete: (supplier) => setToDelete(supplier),
  };

  const createButton = canCreate ? (
    <Button onClick={() => setSheet({ open: true, supplier: null })}>
      <Plus strokeWidth={1.5} aria-hidden />
      Nouveau fournisseur
    </Button>
  ) : null;

  function changeSearch(value: string) {
    setSearch(value);
    setPage(1);
  }

  function changeStatus(value: SupplierStatusFilter) {
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
        title="Fournisseurs"
        description="Ceux qui vous livrent : retrouvez leurs coordonnées au moment d'enregistrer une entrée."
        actions={createButton}
      />

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <SearchInput
          size="lg"
          aria-label="Rechercher un fournisseur"
          placeholder="Rechercher par nom, contact, téléphone ou ville"
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
          <Alert variant="danger" title="Impossible de charger les fournisseurs">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : suppliers.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucun fournisseur ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Truck}
            title="Aucun fournisseur"
            description="Ajoutez ceux qui vous livrent pour les retrouver en un clic lors de vos entrées de stock."
            action={createButton}
          />
        )
      ) : (
        <div className="space-y-5">
          <div
            className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}
          >
            <SupplierTable suppliers={suppliers} busy={busy} handlers={handlers} />
            <SupplierCards suppliers={suppliers} busy={busy} handlers={handlers} />
          </div>

          {query.data ? (
            <Pagination
              meta={query.data.meta}
              itemLabel={{ singular: "fournisseur", plural: "fournisseurs" }}
              onPageChange={setPage}
            />
          ) : null}
        </div>
      )}

      <SupplierSheet
        open={sheet.open}
        supplier={sheet.supplier}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(open) => {
          if (!open && !mutations.remove.isPending) setToDelete(null);
        }}
        title="Supprimer ce fournisseur ?"
        description={
          toDelete
            ? `« ${toDelete.name} » sera supprimé définitivement. Cette action est irréversible.`
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