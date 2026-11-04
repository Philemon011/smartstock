"use client";

import { Plus, Ruler, SearchX } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { PageHeader } from "@/components/app/page-header";
import { UnitActions, type UnitHandlers } from "@/components/units/unit-actions";
import { UnitSheet } from "@/components/units/unit-sheet";
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
import { useUnitMutations, useUnits } from "@/hooks/use-units";
import { ApiError } from "@/lib/api/client";
import type { Unit, UnitStatusFilter } from "@/lib/api/units";
import { cn } from "@/lib/utils";

const STATUS_OPTIONS = [
  { value: "all", label: "Toutes" },
  { value: "active", label: "Actives" },
  { value: "inactive", label: "Désactivées" },
] as const;

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

function StatusBadge({ unit }: { unit: Unit }) {
  return unit.is_active ? (
    <Badge variant="success">Active</Badge>
  ) : (
    <Badge variant="neutral">Désactivée</Badge>
  );
}

function DecimalsLabel({ unit }: { unit: Unit }) {
  return unit.allows_decimals ? <>Oui (ex. 2,5)</> : <>Non (entiers)</>;
}

function ListSkeleton() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 5 }).map((_, index) => (
        <Skeleton key={index} className="h-14 w-full rounded-xl" />
      ))}
    </div>
  );
}

type ListProps = {
  units: Unit[];
  busy: boolean;
  handlers: UnitHandlers;
};

function UnitTable({ units, busy, handlers }: ListProps) {
  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Unité</TableHead>
            <TableHead>Symbole</TableHead>
            <TableHead>Décimales</TableHead>
            <TableHead>Statut</TableHead>
            <TableHead className="w-16">
              <span className="sr-only">Actions</span>
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {units.map((unit) => (
            <TableRow key={unit.id}>
              <TableCell className="font-medium text-foreground">{unit.name}</TableCell>
              <TableCell>
                <Badge>{unit.symbol}</Badge>
              </TableCell>
              <TableCell className="text-muted-foreground">
                <DecimalsLabel unit={unit} />
              </TableCell>
              <TableCell>
                <StatusBadge unit={unit} />
              </TableCell>
              <TableCell className="text-right">
                <UnitActions unit={unit} busy={busy} handlers={handlers} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function UnitCards({ units, busy, handlers }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {units.map((unit) => (
        <li key={unit.id} className="rounded-xl border bg-card p-4 shadow-card">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 space-y-1.5">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium text-foreground">{unit.name}</span>
                <Badge>{unit.symbol}</Badge>
              </div>
              <p className="text-sm text-muted-foreground">
                Décimales : <DecimalsLabel unit={unit} />
              </p>
            </div>

            <UnitActions unit={unit} busy={busy} handlers={handlers} />
          </div>

          <div className="mt-3">
            <StatusBadge unit={unit} />
          </div>
        </li>
      ))}
    </ul>
  );
}

export function UnitsView() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<UnitStatusFilter>("all");
  const [sheet, setSheet] = useState<{ open: boolean; unit: Unit | null }>({
    open: false,
    unit: null,
  });
  const [toDelete, setToDelete] = useState<Unit | null>(null);

  const debouncedSearch = useDebouncedValue(search, 300);
  const filters = { search: debouncedSearch.trim(), status };
  const hasFilters = filters.search !== "" || status !== "all";

  const query = useUnits(filters);
  const mutations = useUnitMutations();

  const busy =
    mutations.activate.isPending || mutations.deactivate.isPending || mutations.remove.isPending;

  const units = query.data?.data ?? [];
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
      toast.success("Unité supprimée.");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setToDelete(null);
    }
  }

  const handlers: UnitHandlers = {
    onEdit: (unit) => setSheet({ open: true, unit }),
    onActivate: (unit) =>
      void run(() => mutations.activate.mutateAsync(unit.id), "Unité réactivée."),
    onDeactivate: (unit) =>
      void run(() => mutations.deactivate.mutateAsync(unit.id), "Unité désactivée."),
    onDelete: (unit) => setToDelete(unit),
  };

  const createButton = canCreate ? (
    <Button onClick={() => setSheet({ open: true, unit: null })}>
      <Plus strokeWidth={1.5} aria-hidden />
      Nouvelle unité
    </Button>
  ) : null;

  function resetFilters() {
    setSearch("");
    setStatus("all");
  }

  return (
    <div className="space-y-8">
      <PageHeader
        title="Unités de mesure"
        description="Sac, kilo, litre, carton… l'unité dans laquelle vous comptez et vendez vos produits."
        actions={createButton}
      />

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <SearchInput
          size="lg"
          aria-label="Rechercher une unité"
          placeholder="Rechercher par nom ou symbole"
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
          <Alert variant="danger" title="Impossible de charger les unités">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : units.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucune unité ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Ruler}
            title="Aucune unité"
            description="Créez l'unité dans laquelle vous comptez vos produits : sac, kilo, carton…"
            action={createButton}
          />
        )
      ) : (
        <div className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}>
          <UnitTable units={units} busy={busy} handlers={handlers} />
          <UnitCards units={units} busy={busy} handlers={handlers} />
        </div>
      )}

      <UnitSheet
        open={sheet.open}
        unit={sheet.unit}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(open) => {
          if (!open && !mutations.remove.isPending) setToDelete(null);
        }}
        title="Supprimer cette unité ?"
        description={
          toDelete
            ? `« ${toDelete.name} » sera supprimée définitivement. Cette action est irréversible.`
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