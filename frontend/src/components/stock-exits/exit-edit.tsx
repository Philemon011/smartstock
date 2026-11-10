"use client";

import Link from "next/link";
import { PageHeader } from "@/components/app/page-header";
import { ExitForm } from "@/components/stock-exits/exit-form";
import { Alert } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useArticlesByIds } from "@/hooks/use-articles-by-ids";
import { useStockExit } from "@/hooks/use-stock-exits";
import { ApiError } from "@/lib/api/client";

function Loading() {
  return (
    <div className="max-w-4xl space-y-6">
      <Skeleton className="h-10 w-64" />
      <Skeleton className="h-64 w-full rounded-xl" />
      <Skeleton className="h-64 w-full rounded-xl" />
    </div>
  );
}

export function ExitEdit({ id }: { id: string }) {
  const query = useStockExit(id);
  const articleIds = [...new Set((query.data?.lines ?? []).map((line) => line.article.id))];
  const articles = useArticlesByIds(articleIds);

  if (query.isPending || (query.data && articles.isPending)) {
    return <Loading />;
  }

  if (query.isError || articles.isError) {
    return (
      <div className="max-w-4xl space-y-4">
        <Alert variant="danger" title="Impossible de charger la sortie">
          {query.error instanceof ApiError
            ? query.error.message
            : "Une erreur inattendue est survenue. Réessayez."}
        </Alert>
        <div className="flex gap-3">
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
          <Link href="/stock-exits" className={buttonVariants({ variant: "ghost" })}>
            Retour aux sorties
          </Link>
        </div>
      </div>
    );
  }

  const exit = query.data;

  if (!exit.can.update) {
    return (
      <div className="max-w-4xl space-y-4">
        <Alert variant="info" title="Cette sortie ne peut plus être modifiée">
          Seul un brouillon est modifiable. Une sortie validée se corrige par une annulation.
        </Alert>
        <Link href={`/stock-exits/${exit.id}`} className={buttonVariants({ variant: "secondary" })}>
          Voir la sortie
        </Link>
      </div>
    );
  }

  return (
    <div className="max-w-4xl space-y-8">
      <PageHeader title="Modifier le brouillon" description="Le stock ne change qu'à la validation." />
      <ExitForm exit={exit} initialArticles={articles.articles} />
    </div>
  );
}