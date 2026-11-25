"use client";

import Link from "next/link";
import { PageHeader } from "@/components/app/page-header";
import { TransferForm } from "@/components/stock-transfers/transfer-form";
import { Alert } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useArticlesByIds } from "@/hooks/use-articles-by-ids";
import { useStockTransfer } from "@/hooks/use-stock-transfers";
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

export function TransferEdit({ id }: { id: string }) {
  const query = useStockTransfer(id);
  const articleIds = [...new Set((query.data?.lines ?? []).map((line) => line.article.id))];
  const articles = useArticlesByIds(articleIds);

  if (query.isPending || (query.data && articles.isPending)) {
    return <Loading />;
  }

  if (query.isError || articles.isError) {
    return (
      <div className="max-w-4xl space-y-4">
        <Alert variant="danger" title="Impossible de charger le transfert">
          {query.error instanceof ApiError
            ? query.error.message
            : "Une erreur inattendue est survenue. Réessayez."}
        </Alert>
        <div className="flex gap-3">
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
          <Link href="/stock-transfers" className={buttonVariants({ variant: "ghost" })}>
            Retour aux transferts
          </Link>
        </div>
      </div>
    );
  }

  const transfer = query.data;

  if (!transfer.can.update) {
    return (
      <div className="max-w-4xl space-y-4">
        <Alert variant="info" title="Ce transfert ne peut plus être modifié">
          Seul un brouillon est modifiable. Un transfert validé se corrige par une annulation.
        </Alert>
        <Link href={`/stock-transfers/${transfer.id}`} className={buttonVariants({ variant: "secondary" })}>
          Voir le transfert
        </Link>
      </div>
    );
  }

  return (
    <div className="max-w-4xl space-y-8">
      <PageHeader title="Modifier le brouillon" description="Le stock ne change qu'à la validation." />
      <TransferForm transfer={transfer} initialArticles={articles.articles} />
    </div>
  );
}