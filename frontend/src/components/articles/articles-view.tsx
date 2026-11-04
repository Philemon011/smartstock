"use client";

import { Package, Plus, SearchX } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";
import { PageHeader } from "@/components/app/page-header";
import { ArticleActions, type ArticleHandlers } from "@/components/articles/article-actions";
import { ArticleThumb } from "@/components/articles/article-thumb";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
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
import { useArticleMutations, useArticles } from "@/hooks/use-articles";
import { useCategories } from "@/hooks/use-categories";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import type { Article, ArticleStatusFilter } from "@/lib/api/articles";
import { ApiError } from "@/lib/api/client";
import { formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";

const ALL_CATEGORIES = "__all__";

const STATUS_OPTIONS = [
  { value: "all", label: "Tous" },
  { value: "active", label: "Actifs" },
  { value: "inactive", label: "Désactivés" },
] as const;

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

function StatusBadge({ article }: { article: Article }) {
  return article.is_active ? (
    <Badge variant="success">Actif</Badge>
  ) : (
    <Badge variant="neutral">Désactivé</Badge>
  );
}

function ListSkeleton() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 5 }).map((_, index) => (
        <Skeleton key={index} className="h-[4.5rem] w-full rounded-xl" />
      ))}
    </div>
  );
}

type ListProps = {
  articles: Article[];
  currency: string;
  busy: boolean;
  handlers: ArticleHandlers;
};

function ArticleTable({ articles, currency, busy, handlers }: ListProps) {
  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Article</TableHead>
            <TableHead className="hidden lg:table-cell">Catégorie</TableHead>
            <TableHead>Unité</TableHead>
            <TableHead className="text-right">Prix de vente</TableHead>
            <TableHead>Statut</TableHead>
            <TableHead className="w-16">
              <span className="sr-only">Actions</span>
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {articles.map((article) => (
            <TableRow key={article.id}>
              <TableCell>
                <div className="flex items-center gap-3">
                  <ArticleThumb name={article.name} imageUrl={article.image_url} />
                  <div className="min-w-0">
                    <Link
                      href={`/articles/${article.id}`}
                      className="block truncate font-medium text-foreground underline-offset-4 hover:underline"
                    >
                      {article.name}
                    </Link>
                    <p className="mt-0.5 text-xs text-muted-foreground">{article.code}</p>
                  </div>
                </div>
              </TableCell>

              <TableCell className="hidden text-muted-foreground lg:table-cell">
                {article.category?.name ?? "—"}
              </TableCell>

              <TableCell className="text-muted-foreground">{article.unit.symbol}</TableCell>

              <TableCell className="text-right font-medium tabular-nums text-foreground">
                {formatMoney(article.sale_price, currency)}
              </TableCell>

              <TableCell>
                <StatusBadge article={article} />
              </TableCell>

              <TableCell className="text-right">
                <ArticleActions article={article} busy={busy} handlers={handlers} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function ArticleCards({ articles, currency, busy, handlers }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {articles.map((article) => (
        <li key={article.id} className="rounded-xl border bg-card p-4 shadow-card">
          <div className="flex items-start gap-3">
            <ArticleThumb name={article.name} imageUrl={article.image_url} className="size-12" />

            <div className="min-w-0 flex-1">
              <Link
                href={`/articles/${article.id}`}
                className="block font-medium text-foreground underline-offset-4 hover:underline"
              >
                {article.name}
              </Link>
              <p className="mt-0.5 text-xs text-muted-foreground">
                {article.code}
                {article.category ? ` · ${article.category.name}` : ""}
              </p>
            </div>

            <ArticleActions article={article} busy={busy} handlers={handlers} />
          </div>

          <div className="mt-3 flex items-center justify-between gap-3">
            <p className="text-sm tabular-nums text-foreground">
              <span className="font-medium">{formatMoney(article.sale_price, currency)}</span>
              <span className="text-muted-foreground"> / {article.unit.symbol}</span>
            </p>
            <StatusBadge article={article} />
          </div>
        </li>
      ))}
    </ul>
  );
}

export function ArticlesView() {
  const { tenant } = useActiveMembership();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<ArticleStatusFilter>("all");
  const [categoryId, setCategoryId] = useState<string>(ALL_CATEGORIES);
  const [page, setPage] = useState(1);
  const [toDelete, setToDelete] = useState<Article | null>(null);

  const debouncedSearch = useDebouncedValue(search, 300);
  const filters = {
    search: debouncedSearch.trim(),
    status,
    categoryId: categoryId === ALL_CATEGORIES ? null : categoryId,
    page,
  };
  const hasFilters = filters.search !== "" || status !== "all" || filters.categoryId !== null;

  const query = useArticles(filters);
  const categories = useCategories({ search: "", status: "all" });
  const mutations = useArticleMutations();

  const busy =
    mutations.activate.isPending || mutations.deactivate.isPending || mutations.remove.isPending;

  const articles = query.data?.data ?? [];
  const canCreate = query.data?.meta.can.create ?? false;

  const categoryNames = new Map((categories.data?.data ?? []).map((item) => [item.id, item.name]));
  const categoryOptions = [
    { value: ALL_CATEGORIES, label: "Toutes les catégories" },
    ...(categories.data?.data ?? []).map((item) => ({
      value: item.id,
      label: item.parent_id ? `${categoryNames.get(item.parent_id) ?? ""} › ${item.name}` : item.name,
    })),
  ];

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
    const wasLastOnPage = articles.length === 1 && page > 1;

    try {
      await mutations.remove.mutateAsync(target.id);
      toast.success("Article supprimé.");

      if (wasLastOnPage) {
        setPage(page - 1);
      }
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setToDelete(null);
    }
  }

  const handlers: ArticleHandlers = {
    onActivate: (article) =>
      void run(() => mutations.activate.mutateAsync(article.id), "Article réactivé."),
    onDeactivate: (article) =>
      void run(() => mutations.deactivate.mutateAsync(article.id), "Article désactivé."),
    onDelete: (article) => setToDelete(article),
  };

  const createButton = canCreate ? (
    <Link href="/articles/new" className={buttonVariants()}>
      <span className="inline-flex items-center gap-2">
        <Plus className="size-4" strokeWidth={1.5} aria-hidden />
        Nouvel article
      </span>
    </Link>
  ) : null;

  function resetFilters() {
    setSearch("");
    setStatus("all");
    setCategoryId(ALL_CATEGORIES);
    setPage(1);
  }

  return (
    <div className="space-y-8">
      <PageHeader
        title="Articles"
        description="Votre catalogue : chaque produit que vous achetez, stockez et vendez."
        actions={createButton}
      />

      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <SearchInput
            size="lg"
            aria-label="Rechercher un article"
            placeholder="Nom, code ou code-barres"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
            className="sm:w-72"
          />

          <Combobox
            size="lg"
            options={categoryOptions}
            value={categoryId}
            onValueChange={(value) => {
              setCategoryId(value);
              setPage(1);
            }}
            searchPlaceholder="Rechercher une catégorie"
            emptyMessage="Aucune catégorie ne correspond."
            className="sm:w-64"
          />
        </div>

        <SegmentedControl
          label="Filtrer par statut"
          options={STATUS_OPTIONS}
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          className="self-start"
        />
      </div>

      {query.isPending ? (
        <ListSkeleton />
      ) : query.isError && !query.data ? (
        <div className="space-y-4">
          <Alert variant="danger" title="Impossible de charger les articles">
            {errorMessage(query.error)}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : articles.length === 0 ? (
        hasFilters ? (
          <EmptyState
            icon={SearchX}
            title="Aucun résultat"
            description="Aucun article ne correspond à votre recherche."
            action={
              <Button variant="secondary" onClick={resetFilters}>
                Réinitialiser les filtres
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Package}
            title="Aucun article"
            description="Créez votre premier article : son nom, son unité, son prix de vente et sa photo."
            action={createButton}
          />
        )
      ) : (
        <div className="space-y-5">
          <div
            className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}
          >
            <ArticleTable
              articles={articles}
              currency={tenant.currency}
              busy={busy}
              handlers={handlers}
            />
            <ArticleCards
              articles={articles}
              currency={tenant.currency}
              busy={busy}
              handlers={handlers}
            />
          </div>

          {query.data ? (
            <Pagination
              meta={query.data.meta}
              itemLabel={{ singular: "article", plural: "articles" }}
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
        title="Supprimer cet article ?"
        description={
          toDelete
            ? `« ${toDelete.name} » (${toDelete.code}) sera supprimé définitivement, avec sa photo. Cette action est irréversible.`
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