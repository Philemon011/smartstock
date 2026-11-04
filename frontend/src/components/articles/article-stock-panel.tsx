"use client";

import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { useArticleStock } from "@/hooks/use-stock-entries";
import type { Article } from "@/lib/api/articles";
import type { StockLot } from "@/lib/api/article-stock";
import { ApiError } from "@/lib/api/client";
import { formatDate, formatMoney, formatQuantity } from "@/lib/format";
import { useActiveMembership } from "@/components/session/session-provider";

function ExpiryBadge({ lot, alertDays }: { lot: StockLot; alertDays: number }) {
  if (lot.expiry_date === null || lot.days_to_expiry === null) {
    return <span className="text-sm text-muted-foreground">Pas d&apos;expiration</span>;
  }

  if (lot.days_to_expiry < 0) {
    const days = Math.abs(lot.days_to_expiry);

    return <Badge variant="danger">Expiré depuis {days} j</Badge>;
  }

  if (lot.days_to_expiry <= alertDays) {
    return (
      <Badge variant="warning">
        {lot.days_to_expiry === 0 ? "Expire aujourd'hui" : `Expire dans ${lot.days_to_expiry} j`}
      </Badge>
    );
  }

  return <span className="text-sm text-muted-foreground">{formatDate(lot.expiry_date)}</span>;
}

export function ArticleStockPanel({ article }: { article: Article }) {
  const { tenant } = useActiveMembership();
  const query = useArticleStock(article.id);

  return (
    <section className="rounded-xl border bg-card p-6 shadow-card">
      <h2 className="text-lg">Stock</h2>

      {query.isPending ? (
        <Skeleton className="mt-5 h-32 w-full rounded-lg" />
      ) : query.isError ? (
        <Alert variant="danger" title="Stock indisponible" className="mt-5">
          {query.error instanceof ApiError
            ? query.error.message
            : "Une erreur inattendue est survenue. Réessayez."}
        </Alert>
      ) : (
        <StockContent article={article} stock={query.data} currency={tenant.currency} />
      )}
    </section>
  );
}

function StockContent({
  article,
  stock,
  currency,
}: {
  article: Article;
  stock: NonNullable<ReturnType<typeof useArticleStock>["data"]>;
  currency: string;
}) {
  const total = Number(stock.total);
  const minimum = Number(article.min_stock);
  const showCosts = stock.lots.some((lot) => lot.unit_cost !== undefined);

  return (
    <div className="mt-5 space-y-6">
      <div className="flex flex-wrap items-end gap-x-4 gap-y-2">
        <p className="text-4xl font-semibold tabular-nums text-foreground">
          {formatQuantity(stock.total)}
          <span className="ml-2 text-lg font-normal text-muted-foreground">{stock.unit.symbol}</span>
        </p>

        {total === 0 ? (
          <Badge variant="danger">Rupture</Badge>
        ) : minimum > 0 && total <= minimum ? (
          <Badge variant="warning">Stock bas</Badge>
        ) : null}
      </div>

      {stock.lots.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Aucun stock pour le moment. Les lots apparaîtront ici dès la validation d&apos;une entrée.
        </p>
      ) : (
        <>
          {stock.warehouses.length > 1 ? (
            <ul className="space-y-2 border-t pt-5">
              {stock.warehouses.map((item) => (
                <li key={item.warehouse.id} className="flex items-center justify-between gap-4 text-sm">
                  <span className="text-muted-foreground">{item.warehouse.name}</span>
                  <span className="font-medium tabular-nums text-foreground">
                    {formatQuantity(item.quantity)} {stock.unit.symbol}
                  </span>
                </li>
              ))}
            </ul>
          ) : null}

          <div className="space-y-3 border-t pt-5">
            <h3 className="text-sm font-medium text-foreground">Lots en stock</h3>

            <ul className="space-y-3">
              {stock.lots.map((lot) => (
                <li
                  key={lot.id}
                  className="flex flex-col gap-3 rounded-lg border bg-background p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge>{lot.code}</Badge>
                      {lot.lot_number ? (
                        <span className="text-xs text-muted-foreground">N° {lot.lot_number}</span>
                      ) : null}
                    </div>
                    <p className="text-xs text-muted-foreground">
                      {lot.warehouse.name} · reçu le {formatDate(lot.received_date)}
                    </p>
                  </div>

                  <div className="sm:text-center">
                    <ExpiryBadge lot={lot} alertDays={article.expiry_alert_days} />
                  </div>

                  <div className="text-sm sm:text-right">
                    <p className="font-medium tabular-nums text-foreground">
                      {formatQuantity(lot.quantity_remaining)} {stock.unit.symbol}
                      <span className="font-normal text-muted-foreground">
                        {" "}
                        / {formatQuantity(lot.quantity_initial)}
                      </span>
                    </p>
                    {showCosts && lot.unit_cost !== undefined && lot.value !== undefined ? (
                      <p className="mt-0.5 text-xs tabular-nums text-muted-foreground">
                        {formatMoney(lot.unit_cost, currency)} / {stock.unit.symbol} · valeur{" "}
                        {formatMoney(lot.value, currency)}
                      </p>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          </div>
        </>
      )}
    </div>
  );
}