"use client";

import { CircleCheck, PackageMinus, PackagePlus } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { PageHeader } from "@/components/app/page-header";
import { alertMeta, alertOrder } from "@/components/alerts/alert-meta";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { Pagination } from "@/components/ui/pagination";
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
import { useAlertSummary, useAlerts } from "@/hooks/use-alerts";
import type { AlertRow, AlertType } from "@/lib/api/alerts";
import { ApiError } from "@/lib/api/client";
import { formatDate, formatMoney, formatQuantity } from "@/lib/format";
import { cn } from "@/lib/utils";

function ListSkeleton() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 5 }).map((_, index) => (
        <Skeleton key={index} className="h-16 w-full rounded-xl" />
      ))}
    </div>
  );
}

function ArticleCell({ row }: { row: AlertRow }) {
  return (
    <>
      <Link
        href={`/articles/${row.article.id}`}
        className="font-medium text-foreground underline-offset-4 hover:underline"
      >
        {row.article.name}
      </Link>
      <p className="mt-0.5 text-xs text-muted-foreground">{row.article.code}</p>
    </>
  );
}

function ExpiryBadge({ days }: { days: number }) {
  if (days < 0) {
    return <Badge variant="danger">Expiré depuis {Math.abs(days)} j</Badge>;
  }

  return <Badge variant="warning">{days === 0 ? "Expire aujourd'hui" : `Dans ${days} j`}</Badge>;
}

/** Ouvre le formulaire de sortie déjà rempli pour détruire ce lot expiré. */
function ExitLotLink({ row }: { row: AlertRow }) {
  if (!row.lot) return null;

  const params = new URLSearchParams({ article: row.article.id, lot: row.lot.id });

  return (
    <Link
      href={`/stock-exits/new?${params.toString()}`}
      className={buttonVariants({ variant: "secondary", size: "sm" })}
    >
      <span className="inline-flex items-center gap-2">
        <PackageMinus className="size-4" strokeWidth={1.5} aria-hidden />
        Sortir ce lot
      </span>
    </Link>
  );
}

type ListProps = {
  rows: AlertRow[];
  currency: string;
  canExit: boolean;
};

function LotTable({ rows, currency, canExit }: ListProps) {
  const showValue = rows.some((row) => row.lot?.value !== undefined);

  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Article</TableHead>
            <TableHead>Lot</TableHead>
            <TableHead>Expiration</TableHead>
            <TableHead className="text-right">Quantité</TableHead>
            {showValue ? <TableHead className="text-right">Valeur</TableHead> : null}
            {canExit ? (
              <TableHead className="w-40">
                <span className="sr-only">Action</span>
              </TableHead>
            ) : null}
          </TableRow>
        </TableHeader>

        <TableBody>
          {rows.map((row) =>
            row.lot ? (
              <TableRow key={row.id}>
                <TableCell>
                  <ArticleCell row={row} />
                </TableCell>
                <TableCell>
                  <Badge>{row.lot.code}</Badge>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {row.lot.warehouse}
                    {row.lot.lot_number ? ` · N° ${row.lot.lot_number}` : ""}
                  </p>
                </TableCell>
                <TableCell>
                  <ExpiryBadge days={row.lot.days_to_expiry} />
                  <p className="mt-1 text-xs text-muted-foreground">{formatDate(row.lot.expiry_date)}</p>
                </TableCell>
                <TableCell className="text-right font-medium tabular-nums text-foreground">
                  {formatQuantity(row.lot.quantity)} {row.article.unit.symbol}
                </TableCell>
                {showValue ? (
                  <TableCell className="text-right tabular-nums text-muted-foreground">
                    {row.lot.value ? formatMoney(row.lot.value, currency) : "—"}
                  </TableCell>
                ) : null}
                {canExit ? (
                  <TableCell className="text-right">
                    <ExitLotLink row={row} />
                  </TableCell>
                ) : null}
              </TableRow>
            ) : null,
          )}
        </TableBody>
      </Table>
    </div>
  );
}

function LotCards({ rows, currency, canExit }: ListProps) {
  return (
    <ul className="space-y-3 md:hidden">
      {rows.map((row) =>
        row.lot ? (
          <li key={row.id} className="rounded-xl border bg-card p-4 shadow-card">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <ArticleCell row={row} />
              </div>
              <ExpiryBadge days={row.lot.days_to_expiry} />
            </div>

            <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt className="text-muted-foreground">Lot</dt>
                <dd className="mt-0.5 font-medium text-foreground">{row.lot.code}</dd>
                <dd className="text-xs text-muted-foreground">{row.lot.warehouse}</dd>
              </div>
              <div>
                <dt className="text-muted-foreground">Expiration</dt>
                <dd className="mt-0.5 font-medium text-foreground">{formatDate(row.lot.expiry_date)}</dd>
              </div>
              <div>
                <dt className="text-muted-foreground">Quantité</dt>
                <dd className="mt-0.5 font-medium tabular-nums text-foreground">
                  {formatQuantity(row.lot.quantity)} {row.article.unit.symbol}
                </dd>
              </div>
              {row.lot.value ? (
                <div>
                  <dt className="text-muted-foreground">Valeur</dt>
                  <dd className="mt-0.5 font-medium tabular-nums text-foreground">
                    {formatMoney(row.lot.value, currency)}
                  </dd>
                </div>
              ) : null}
            </dl>

            {canExit ? (
              <div className="mt-4 border-t pt-3">
                <ExitLotLink row={row} />
              </div>
            ) : null}
          </li>
        ) : null,
      )}
    </ul>
  );
}

function StockTable({ rows }: { rows: AlertRow[] }) {
  return (
    <div className="hidden overflow-hidden rounded-xl border bg-card shadow-card md:block">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Article</TableHead>
            <TableHead className="text-right">Stock actuel</TableHead>
            <TableHead className="text-right">Seuil d&apos;alerte</TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {rows.map((row) =>
            row.stock ? (
              <TableRow key={row.id}>
                <TableCell>
                  <ArticleCell row={row} />
                </TableCell>
                <TableCell className="text-right font-medium tabular-nums text-foreground">
                  {formatQuantity(row.stock.quantity)} {row.article.unit.symbol}
                </TableCell>
                <TableCell className="text-right tabular-nums text-muted-foreground">
                  {formatQuantity(row.stock.minimum)} {row.article.unit.symbol}
                </TableCell>
              </TableRow>
            ) : null,
          )}
        </TableBody>
      </Table>
    </div>
  );
}

function StockCards({ rows }: { rows: AlertRow[] }) {
  return (
    <ul className="space-y-3 md:hidden">
      {rows.map((row) =>
        row.stock ? (
          <li key={row.id} className="rounded-xl border bg-card p-4 shadow-card">
            <ArticleCell row={row} />

            <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt className="text-muted-foreground">Stock actuel</dt>
                <dd className="mt-0.5 font-medium tabular-nums text-foreground">
                  {formatQuantity(row.stock.quantity)} {row.article.unit.symbol}
                </dd>
              </div>
              <div>
                <dt className="text-muted-foreground">Seuil d&apos;alerte</dt>
                <dd className="mt-0.5 font-medium tabular-nums text-foreground">
                  {formatQuantity(row.stock.minimum)} {row.article.unit.symbol}
                </dd>
              </div>
            </dl>
          </li>
        ) : null,
      )}
    </ul>
  );
}

export function AlertsView({ initialType }: { initialType: AlertType }) {
  const { tenant, abilities } = useActiveMembership();
  const [type, setType] = useState<AlertType>(initialType);
  const [page, setPage] = useState(1);

  const summary = useAlertSummary();
  const query = useAlerts(type, page);

  const meta = alertMeta[type];
  const isLotType = type === "expired" || type === "expiring";
  const canExit = abilities.manage_stock && type === "expired";
  const rows = query.data?.data ?? [];

  const options = alertOrder.map((value) => ({
    value,
    label: summary.data ? `${alertMeta[value].label} (${summary.data[value]})` : alertMeta[value].label,
  }));

  const entryButton =
    abilities.manage_stock && !isLotType ? (
      <Link href="/stock-entries/new" className={buttonVariants()}>
        <span className="inline-flex items-center gap-2">
          <PackagePlus className="size-4" strokeWidth={1.5} aria-hidden />
          Enregistrer une entrée
        </span>
      </Link>
    ) : null;

  return (
    <div className="space-y-8">
      <PageHeader
        title="Alertes"
        description="Ce qui demande votre attention : expirations, ruptures et stocks bas."
        actions={entryButton}
      />

      <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <SegmentedControl
          label="Type d'alerte"
          options={options}
          value={type}
          onChange={(value) => {
            setType(value);
            setPage(1);
          }}
        />
      </div>

      {query.isPending ? (
        <ListSkeleton />
      ) : query.isError && !query.data ? (
        <div className="space-y-4">
          <Alert variant="danger" title="Impossible de charger les alertes">
            {query.error instanceof ApiError
              ? query.error.message
              : "Une erreur inattendue est survenue. Réessayez."}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : rows.length === 0 ? (
        <EmptyState icon={CircleCheck} title={meta.emptyTitle} description={meta.emptyText} />
      ) : (
        <div className="space-y-5">
          <div className={cn("transition-opacity duration-200", query.isPlaceholderData && "opacity-60")}>
            {isLotType ? (
              <>
                <LotTable rows={rows} currency={tenant.currency} canExit={canExit} />
                <LotCards rows={rows} currency={tenant.currency} canExit={canExit} />
              </>
            ) : (
              <>
                <StockTable rows={rows} />
                <StockCards rows={rows} />
              </>
            )}
          </div>

          {query.data ? (
            <Pagination
              meta={query.data.meta}
              itemLabel={
                isLotType
                  ? { singular: "lot", plural: "lots" }
                  : { singular: "article", plural: "articles" }
              }
              onPageChange={setPage}
            />
          ) : null}
        </div>
      )}
    </div>
  );
}