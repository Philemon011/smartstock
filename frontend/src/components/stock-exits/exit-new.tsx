"use client";

import { ExitForm, type ExitPrefill } from "@/components/stock-exits/exit-form";
import { emptyExitRow } from "@/components/stock-exits/exit-line-editor";
import { Alert } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { useArticlesByIds } from "@/hooks/use-articles-by-ids";
import { useArticleStock } from "@/hooks/use-stock-entries";

/** "20.000" devient "20", "2.500" devient "2.5" : une saisie propre dans le champ. */
function cleanQuantity(value: string): string {
  return value.includes(".") ? value.replace(/\.?0+$/, "") : value;
}

function PrefilledForm({ articleId, lotId }: { articleId: string; lotId: string }) {
  const articles = useArticlesByIds([articleId]);
  const stock = useArticleStock(articleId);

  if (articles.isPending || stock.isPending) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-64 w-full rounded-xl" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }

  const article = articles.articles.get(articleId) ?? null;
  const lot = stock.data?.lots.find((item) => item.id === lotId) ?? null;

  if (!article || !lot) {
    return (
      <div className="space-y-6">
        <Alert variant="info" title="Ce lot n'est plus disponible">
          Il a déjà été sorti ou n&apos;a pas pu être chargé. Vous pouvez enregistrer une autre sortie.
        </Alert>
        <ExitForm exit={null} initialArticles={new Map()} />
      </div>
    );
  }

  const prefill: ExitPrefill = {
    reason: "expiry",
    warehouseId: lot.warehouse.id,
    row: {
      ...emptyExitRow(),
      article,
      quantity: cleanQuantity(lot.quantity_remaining),
      lotId: lot.id,
    },
  };

  return <ExitForm exit={null} initialArticles={new Map()} prefill={prefill} />;
}

export function ExitNew({ articleId, lotId }: { articleId: string | null; lotId: string | null }) {
  if (articleId && lotId) {
    return <PrefilledForm key={lotId} articleId={articleId} lotId={lotId} />;
  }

  return <ExitForm exit={null} initialArticles={new Map()} />;
}