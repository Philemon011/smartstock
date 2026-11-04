"use client";

import { ArrowLeft, Pencil, Power, Trash2 } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";
import { ArticleThumb } from "@/components/articles/article-thumb";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { Skeleton } from "@/components/ui/skeleton";
import { useArticle, useArticleMutations, usePriceHistory } from "@/hooks/use-articles";
import { ApiError } from "@/lib/api/client";
import { formatDateTime, formatMoney, formatQuantity } from "@/lib/format";
import { Package } from "lucide-react";

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.";
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

function PriceHistory({ articleId, currency }: { articleId: string; currency: string }) {
  const history = usePriceHistory(articleId);

  if (history.isPending) {
    return <Skeleton className="h-24 w-full rounded-lg" />;
  }

  if (history.isError) {
    return (
      <Alert variant="danger" title="Historique indisponible">
        {errorMessage(history.error)}
      </Alert>
    );
  }

  return (
    <ol className="space-y-4">
      {history.data.map((change) => (
        <li key={change.id} className="flex items-start justify-between gap-4">
          <div>
            <p className="text-sm text-foreground">
              {change.old_price === null ? (
                <>
                  Prix initial :{" "}
                  <span className="font-medium tabular-nums">
                    {formatMoney(change.new_price, currency)}
                  </span>
                </>
              ) : (
                <>
                  <span className="tabular-nums text-muted-foreground line-through">
                    {formatMoney(change.old_price, currency)}
                  </span>{" "}
                  →{" "}
                  <span className="font-medium tabular-nums">
                    {formatMoney(change.new_price, currency)}
                  </span>
                </>
              )}
            </p>
            {change.changed_by ? (
              <p className="mt-0.5 text-xs text-muted-foreground">par {change.changed_by}</p>
            ) : null}
          </div>

          {change.created_at ? (
            <time dateTime={change.created_at} className="shrink-0 text-xs text-muted-foreground">
              {formatDateTime(change.created_at)}
            </time>
          ) : null}
        </li>
      ))}
    </ol>
  );
}

export function ArticleDetail({ id }: { id: string }) {
  const router = useRouter();
  const { tenant } = useActiveMembership();
  const query = useArticle(id);
  const mutations = useArticleMutations();
  const [confirmDelete, setConfirmDelete] = useState(false);

  if (query.isPending) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-28 w-full rounded-xl" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }

  if (query.isError) {
    const notFound = query.error instanceof ApiError && query.error.status === 404;

    return notFound ? (
      <EmptyState
        icon={Package}
        title="Article introuvable"
        description="Cet article n'existe pas ou a été supprimé."
        action={
          <Link href="/articles" className={buttonVariants({ variant: "secondary" })}>
            Retour aux articles
          </Link>
        }
      />
    ) : (
      <div className="space-y-4">
        <Alert variant="danger" title="Impossible de charger l'article">
          {errorMessage(query.error)}
        </Alert>
        <Button variant="secondary" onClick={() => void query.refetch()}>
          Réessayer
        </Button>
      </div>
    );
  }

  const article = query.data;

  async function toggle() {
    try {
      if (article.is_active) {
        await mutations.deactivate.mutateAsync(article.id);
        toast.success("Article désactivé.");
      } else {
        await mutations.activate.mutateAsync(article.id);
        toast.success("Article réactivé.");
      }
    } catch (error) {
      toast.error(errorMessage(error));
    }
  }

  async function remove() {
    try {
      await mutations.remove.mutateAsync(article.id);
      toast.success("Article supprimé.");
      router.replace("/articles");
    } catch (error) {
      toast.error(errorMessage(error));
      setConfirmDelete(false);
    }
  }

  const togglePending = mutations.activate.isPending || mutations.deactivate.isPending;

  return (
    <div className="space-y-8">
      <Link
        href="/articles"
        className="inline-flex items-center gap-2 text-sm text-muted-foreground transition-colors duration-200 ease-smooth hover:text-foreground"
      >
        <ArrowLeft className="size-4" strokeWidth={1.5} aria-hidden />
        Articles
      </Link>

      <header className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex items-start gap-4">
          <ArticleThumb
            name={article.name}
            imageUrl={article.image_url}
            className="size-20 rounded-lg"
          />

          <div className="min-w-0 space-y-2">
            <h1 className="text-3xl">{article.name}</h1>
            <div className="flex flex-wrap items-center gap-2">
              <Badge>{article.code}</Badge>
              {article.is_active ? (
                <Badge variant="success">Actif</Badge>
              ) : (
                <Badge variant="neutral">Désactivé</Badge>
              )}
            </div>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          {article.can.update ? (
            <Link href={`/articles/${article.id}/edit`} className={buttonVariants({ variant: "secondary" })}>
              <span className="inline-flex items-center gap-2">
                <Pencil className="size-4" strokeWidth={1.5} aria-hidden />
                Modifier
              </span>
            </Link>
          ) : null}

          {article.can.toggle ? (
            <Button variant="secondary" loading={togglePending} onClick={() => void toggle()}>
              <Power strokeWidth={1.5} aria-hidden />
              {article.is_active ? "Désactiver" : "Réactiver"}
            </Button>
          ) : null}

          {article.can.delete ? (
            <Button variant="destructive" onClick={() => setConfirmDelete(true)}>
              <Trash2 strokeWidth={1.5} aria-hidden />
              Supprimer
            </Button>
          ) : null}
        </div>
      </header>

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Informations</h2>

        <dl className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <Detail label="Prix de vente">
            <span className="tabular-nums">{formatMoney(article.sale_price, tenant.currency)}</span>
            <span className="font-normal text-muted-foreground"> / {article.unit.symbol}</span>
          </Detail>
          <Detail label="Unité">
            {article.unit.name} ({article.unit.symbol})
          </Detail>
          <Detail label="Catégorie">{article.category?.name ?? "—"}</Detail>
          <Detail label="Seuil d'alerte de stock">
            <span className="tabular-nums">
              {formatQuantity(article.min_stock)} {article.unit.symbol}
            </span>
          </Detail>
          <Detail label="Expiration">
            {article.track_expiry
              ? `Suivie, alerte ${article.expiry_alert_days} jours avant`
              : "Non suivie"}
          </Detail>
          <Detail label="Code-barres">{article.barcode ?? "—"}</Detail>
        </dl>

        {article.description ? (
          <p className="mt-6 border-t pt-5 text-sm text-muted-foreground">{article.description}</p>
        ) : null}
      </section>

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Conditionnements</h2>

        {article.packagings.length === 0 ? (
          <p className="mt-3 text-sm text-muted-foreground">
            Aucun : cet article se compte uniquement en {article.unit.name.toLowerCase()}.
          </p>
        ) : (
          <ul className="mt-4 space-y-2">
            {article.packagings.map((packaging) => (
              <li key={packaging.id} className="flex items-center justify-between gap-4 text-sm">
                <span className="font-medium text-foreground">{packaging.name}</span>
                <span className="tabular-nums text-muted-foreground">
                  = {formatQuantity(packaging.quantity)} {article.unit.symbol}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <div className="grid gap-8 lg:grid-cols-2">
        <section className="rounded-xl border bg-card p-6 shadow-card">
          <h2 className="text-lg">Historique des prix de vente</h2>
          <div className="mt-5">
            <PriceHistory articleId={article.id} currency={tenant.currency} />
          </div>
        </section>

        <section className="rounded-xl border border-dashed p-6">
          <h2 className="text-lg">Stock</h2>
          <p className="mt-3 text-sm text-muted-foreground">
            Le stock par entrepôt, les lots avec leurs prix d&apos;achat et leurs dates d&apos;expiration
            apparaîtront ici dès les premières entrées.
          </p>
        </section>
      </div>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={(open) => {
          if (!open && !mutations.remove.isPending) setConfirmDelete(false);
        }}
        title="Supprimer cet article ?"
        description={`« ${article.name} » (${article.code}) sera supprimé définitivement, avec sa photo. Cette action est irréversible.`}
        confirmLabel="Supprimer"
        destructive
        pending={mutations.remove.isPending}
        onConfirm={() => void remove()}
      />
    </div>
  );
}