"use client";

import { Plus, SearchX, Tags } from "lucide-react";
import { useMemo, useState } from "react";
import { toast } from "sonner";
import { PageHeader } from "@/components/app/page-header";
import { CategoryActions, type CategoryHandlers } from "@/components/categories/category-actions";
import { CategorySheet } from "@/components/categories/category-sheet";
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
import { useCategories, useCategoryMutations } from "@/hooks/use-categories";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import type { Category, CategoryStatusFilter } from "@/lib/api/categories";
import { ApiError } from "@/lib/api/client";
import { cn } from "@/lib/utils";

const STATUS_OPTIONS = [
  { value: "all", label: "Toutes" },
  { value: "active", label: "Actives" },
  { value: "inactive", label: "Désactivées" },
] as const;

type Group = { root: Category; children: Category[] };

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

function buildGroups(items: Category[]): Group[] {
  const groups: Group[] = [];
  const byRoot = new Map<string, Group>();

  for (const item of items) {
    if (item.parent_id === null) {
      const group: Group = { root: item, children: [] };

      byRoot.set(item.id, group);
      groups.push(group);
    } else {
      byRoot.get(item.parent_id)?.children.push(item);
    }
  }

  return groups;
}

function StatusBadge({ category }: { category: Category }) {
  return category.is_active ? (
    <Badge variant="success">Active</Badge>
  ) : (
    <Badge variant="neutral">Désactivée</Badge>
  );
}

function childrenLabel(count: number): string {
  return `${count} ${count > 1 ? "sous-catégories" : "sous-catégorie"}`;
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
  groups: Group[];
  busy: boolean;
  handlers: CategoryHandlers;
};

function CategoryTable({ groups, busy, handlers }: ListProps) {
  const rows = groups.flatMap((group) => [group.root, ...group.children]);

  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Nom</TableHead>
            <TableHead className="hidden lg:table-cell">Description</TableHead>
            <TableHead>Statut</TableHead>
            <TableHead className="w-16">
              <span className="sr-only">Actions</span>
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {rows.map((category) => {
            const isChild = category.depth === 1;

            return (
              <TableRow key={category.id}>
                <TableCell>
                  <div className="flex items-center">
                    {isChild ? (
                      <span
                        aria-hidden
                        className="mb-2 ml-3 mr-3 inline-block h-4 w-3 shrink-0 rounded-bl-md border-b border-l border-input"
                      />
                    ) : null}
                    <span className={cn("text-foreground", !isChild && "font-medium")}>
                      {category.name}
                    </span>
                    {!isChild && category.children_count > 0 ? (
                      <span className="ml-3 text-xs text-muted-foreground">
                        {childrenLabel(category.children_count)}
                      </span>
                    ) : null}
                  </div>
                </TableCell>

                <TableCell className="hidden max-w-xs truncate text-muted-foreground lg:table-cell">
                  {category.description ?? "—"}
                </TableCell>

                <TableCell>
                  <StatusBadge category={category} />
                </TableCell>

                <TableCell className="text-right">
                  <CategoryActions category={category} busy={busy} handlers={handlers} />
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
}

function CategoryCards({ groups, busy, handlers }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {groups.map(({ root, children }) => (
        <li key={root.id} className="rounded-xl border bg-card shadow-card">
          <div className="flex items-start justify-between gap-3 p-4">
            <div className="min-w-0 space-y-1.5">
              <p className="font-medium text-foreground">{root.name}</p>
              {root.description ? (
                <p className="line-clamp-2 text-sm text-muted-foreground">{root.description}</p>
              ) : null}
              <div className="flex flex-wrap items-center gap-2 pt-0.5">
                <StatusBadge category={root} />
                {root.children_count > 0 ? (
                  <span className="text-xs text-muted-foreground">
                    {childrenLabel(root.children_count)}
                  </span>
                ) : null}
              </div>
            </div>

            <CategoryActions category={root} busy={busy} handlers={handlers} />
          </div>

          {children.length > 0 ? (
            <ul className="border-t">
              {children.map((child) => (
                <li
                  key={child.id}
                  className="flex items-center justify-between gap-3 border-b px-4 py-3 pl-6 last:border-b-0"
                >
                  <div className="min-w-0 space-y-1">
                    <p className="truncate text-sm text-foreground">{child.name}</p>
                    <StatusBadge category={child} />
                  </div>
                  <CategoryActions category={child} busy={busy} handlers={handlers} />
                </li>
              ))}
            </ul>
          ) : null}
        </li>
      ))}
    </ul>
  );
}

export function CategoriesView() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<CategoryStatusFilter>("all");
  const [sheet, setSheet] = useState<{
    open: boolean;
    category: Category | null;
    parentId: string | null;
  }>({ open: false, category: null, parentId: null });
  const [toDelete, setToDelete] = useState<Category | null>(null);

  const debouncedSearch = useDebouncedValue(search, 300);
  const filters = { search: debouncedSearch.trim(), status };
  const hasFilters = filters.search !== "" || status !== "all";

  const query = useCategories(filters);
  const mutations = useCategoryMutations();

  const busy =
    mutations.activate.isPending || mutations.deactivate.isPending || mutations.remove.isPending;

  const items = query.data?.data;
  const groups = useMemo(() => buildGroups(items ?? []), [items]);
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
      toast.success("Catégorie supprimée.");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setToDelete(null);
    }
  }

  const handlers: CategoryHandlers = {
    onEdit: (category) => setSheet({ open: true, category, parentId: null }),
    onAddChild: (category) => setSheet({ open: true, category: null, parentId: category.id }),
    onActivate: (category) =>
      void run(() => mutations.activate.mutateAsync(category.id), "Catégorie réactivée."),
    onDeactivate: (category) =>
      void run(
        () => mutations.deactivate.mutateAsync(category.id),
        category.children_count > 0
          ? "Catégorie et sous-catégories désactivées."
          : "Catégorie désactivée.",
      ),
    onDelete: (category) => setToDelete(category),
  };

  const createButton = canCreate ? (
    <Button onClick={() => setSheet({ open: true, category: null, parentId: null })}>
      <Plus strokeWidth={1.5} aria-hidden />
      Nouvelle catégorie
    </Button>
  ) : null;

  function resetFilters() {
    setSearch("");
    setStatus("all");
  }

  return (
    <div className="space-y-8">
      <PageHeader
        title="Catégories"
        description="Classez vos articles pour les retrouver et les analyser plus facilement."
        actions={createButton}
      />

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <SearchInput
          size="lg"
          aria-label="Rechercher une catégorie"
          placeholder="Rechercher une catégorie"
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
          <Alert variant="danger" title="Impossible de charger les catégories">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : groups.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucune catégorie ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Tags}
            title="Aucune catégorie"
            description="Créez vos premières catégories, par exemple Boissons ou Alimentation, puis ajoutez des sous-catégories si besoin."
            action={createButton}
          />
        )
      ) : (
        <div className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}>
          <CategoryTable groups={groups} busy={busy} handlers={handlers} />
          <CategoryCards groups={groups} busy={busy} handlers={handlers} />
        </div>
      )}

      <CategorySheet
        open={sheet.open}
        category={sheet.category}
        parentId={sheet.parentId}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(open) => {
          if (!open && !mutations.remove.isPending) setToDelete(null);
        }}
        title="Supprimer cette catégorie ?"
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