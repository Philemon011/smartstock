"use client";

import { Plus, SearchX, Warehouse as WarehouseIcon } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { PageHeader } from "@/components/app/page-header";
import { WarehouseActions, type WarehouseHandlers } from "@/components/warehouses/warehouse-actions";
import { WarehouseSheet } from "@/components/warehouses/warehouse-sheet";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
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
import { useWarehouseMutations, useWarehouses } from "@/hooks/use-warehouses";
import { ApiError } from "@/lib/api/client";
import type { Warehouse, WarehouseStatusFilter } from "@/lib/api/warehouses";
import { cn } from "@/lib/utils";

const STATUS_OPTIONS = [
  { value: "all", label: "Tous" },
  { value: "active", label: "Actifs" },
  { value: "inactive", label: "Désactivés" },
] as const;

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

function StatusBadge({ warehouse }: { warehouse: Warehouse }) {
  return warehouse.is_active ? (
    <Badge variant="success">Actif</Badge>
  ) : (
    <Badge variant="neutral">Désactivé</Badge>
  );
}

function location(warehouse: Warehouse): { primary: string; secondary: string | null } {
  return {
    primary: warehouse.city ?? warehouse.address ?? "—",
    secondary: warehouse.city && warehouse.address ? warehouse.address : null,
  };
}

function ListSkeleton() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 4 }).map((_, index) => (
        <Skeleton key={index} className="h-16 w-full rounded-xl" />
      ))}
    </div>
  );
}

type ListProps = {
  warehouses: Warehouse[];
  busy: boolean;
  handlers: WarehouseHandlers;
};

function WarehouseTable({ warehouses, busy, handlers }: ListProps) {
  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Nom</TableHead>
            <TableHead>Localisation</TableHead>
            <TableHead className="hidden xl:table-cell">Téléphone</TableHead>
            <TableHead>Statut</TableHead>
            <TableHead className="w-16">
              <span className="sr-only">Actions</span>
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {warehouses.map((warehouse) => {
            const place = location(warehouse);

            return (
              <TableRow key={warehouse.id}>
                <TableCell>
                  <div className="flex items-center gap-2">
                    <span className="font-medium text-foreground">{warehouse.name}</span>
                    {warehouse.is_main ? <Badge variant="info">Principal</Badge> : null}
                  </div>
                  {warehouse.code ? (
                    <p className="mt-0.5 text-xs text-muted-foreground">{warehouse.code}</p>
                  ) : null}
                </TableCell>

                <TableCell>
                  <p className="text-foreground">{place.primary}</p>
                  {place.secondary ? (
                    <p className="mt-0.5 max-w-64 truncate text-xs text-muted-foreground">
                      {place.secondary}
                    </p>
                  ) : null}
                </TableCell>

                <TableCell className="hidden text-muted-foreground xl:table-cell">
                  {warehouse.phone ?? "—"}
                </TableCell>

                <TableCell>
                  <StatusBadge warehouse={warehouse} />
                </TableCell>

                <TableCell className="text-right">
                  <WarehouseActions warehouse={warehouse} busy={busy} handlers={handlers} />
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
}

function WarehouseCards({ warehouses, busy, handlers }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {warehouses.map((warehouse) => {
        const place = location(warehouse);

        return (
          <li key={warehouse.id} className="rounded-xl border bg-card p-4 shadow-card">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0 space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium text-foreground">{warehouse.name}</span>
                  {warehouse.is_main ? <Badge variant="info">Principal</Badge> : null}
                </div>
                {warehouse.code ? (
                  <p className="text-xs text-muted-foreground">{warehouse.code}</p>
                ) : null}
              </div>

              <WarehouseActions warehouse={warehouse} busy={busy} handlers={handlers} />
            </div>

            <div className="mt-3 flex items-end justify-between gap-3">
              <div className="min-w-0 text-sm text-muted-foreground">
                <p className="truncate">{place.primary}</p>
                {place.secondary ? <p className="truncate text-xs">{place.secondary}</p> : null}
                {warehouse.phone ? <p className="mt-1 text-xs">{warehouse.phone}</p> : null}
              </div>
              <StatusBadge warehouse={warehouse} />
            </div>
          </li>
        );
      })}
    </ul>
  );
}

export function WarehousesView() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<WarehouseStatusFilter>("all");
  const [sheet, setSheet] = useState<{ open: boolean; warehouse: Warehouse | null }>({
    open: false,
    warehouse: null,
  });
  const [toDelete, setToDelete] = useState<Warehouse | null>(null);

  const debouncedSearch = useDebouncedValue(search, 300);
  const filters = { search: debouncedSearch.trim(), status };
  const hasFilters = filters.search !== "" || status !== "all";

  const query = useWarehouses(filters);
  const mutations = useWarehouseMutations();

  const busy =
    mutations.setMain.isPending ||
    mutations.activate.isPending ||
    mutations.deactivate.isPending ||
    mutations.remove.isPending;

  const warehouses = query.data?.data ?? [];
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

    try {
      await mutations.remove.mutateAsync(target.id);
      toast.success("Entrepôt supprimé.");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setToDelete(null);
    }
  }

  const handlers: WarehouseHandlers = {
    onEdit: (warehouse) => setSheet({ open: true, warehouse }),
    onSetMain: (warehouse) =>
      void run(
        () => mutations.setMain.mutateAsync(warehouse.id),
        `« ${warehouse.name} » est maintenant l'entrepôt principal.`,
      ),
    onActivate: (warehouse) =>
      void run(() => mutations.activate.mutateAsync(warehouse.id), "Entrepôt réactivé."),
    onDeactivate: (warehouse) =>
      void run(() => mutations.deactivate.mutateAsync(warehouse.id), "Entrepôt désactivé."),
    onDelete: (warehouse) => setToDelete(warehouse),
  };

  const createButton = canCreate ? (
    <Button onClick={() => setSheet({ open: true, warehouse: null })}>
      <Plus strokeWidth={1.5} aria-hidden />
      Nouvel entrepôt
    </Button>
  ) : null;

  function resetFilters() {
    setSearch("");
    setStatus("all");
  }

  return (
    <div className="space-y-8">
      <PageHeader
        title="Entrepôts"
        description="Les lieux où votre stock est conservé."
        actions={createButton}
      />

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <SearchInput
          size="lg"
          aria-label="Rechercher un entrepôt"
          placeholder="Rechercher par nom, code ou ville"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          className="sm:w-80"
        />

        <SegmentedControl
          label="Filtrer par statut"
          options={STATUS_OPTIONS}
          value={status}
          onChange={setStatus}
          className="self-start"
        />
      </div>

      {query.isPending ? (
        <ListSkeleton />
      ) : query.isError && !query.data ? (
        <div className="space-y-4">
          <Alert variant="danger" title="Impossible de charger les entrepôts">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : warehouses.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucun entrepôt ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={WarehouseIcon}
            title="Aucun entrepôt"
            description="Créez votre premier lieu de stockage pour commencer à suivre votre stock."
            action={createButton}
          />
        )
      ) : (
        <div className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}>
          <WarehouseTable warehouses={warehouses} busy={busy} handlers={handlers} />
          <WarehouseCards warehouses={warehouses} busy={busy} handlers={handlers} />
        </div>
      )}

      <WarehouseSheet
        open={sheet.open}
        warehouse={sheet.warehouse}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(open) => {
          if (!open && !mutations.remove.isPending) setToDelete(null);
        }}
        title="Supprimer cet entrepôt ?"
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