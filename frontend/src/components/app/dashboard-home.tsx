"use client";

import {
  Bell,
  ChevronRight,
  CircleCheck,
  Coins,
  Package,
  PackageMinus,
  PackagePlus,
  Warehouse,
  type LucideIcon,
} from "lucide-react";
import Link from "next/link";
import { alertMeta, alertOrder, toneClasses } from "@/components/alerts/alert-meta";
import { EntryStatusBadge } from "@/components/stock-entries/entry-status-badge";
import { useActiveMembership, useSession } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useDashboard } from "@/hooks/use-dashboard";
import type { TenantSummary } from "@/lib/api/auth";
import { ApiError } from "@/lib/api/client";
import type { Dashboard } from "@/lib/api/dashboard";
import { formatDate, formatMoney } from "@/lib/format";
import { firstName, pluralize } from "@/lib/session";
import { cn } from "@/lib/utils";

type Kpi = {
  label: string;
  value: string;
  hint?: string;
  icon: LucideIcon;
  tone?: "danger" | "warning" | "success";
};

const GRID_COLUMNS: Record<number, string> = {
  2: "grid-cols-2",
  3: "grid-cols-1 sm:grid-cols-3",
  4: "grid-cols-2 lg:grid-cols-4",
  5: "grid-cols-2 xl:grid-cols-5",
};

const VALUE_TONES = {
  danger: "text-danger",
  warning: "text-warning",
  success: "text-success",
} as const;

function TrialNotice({ tenant }: { tenant: TenantSummary }) {
  if (tenant.status === "trial" && tenant.trial_days_remaining !== null) {
    return (
      <Alert variant="info" title="Essai gratuit">
        Il vous reste {pluralize(tenant.trial_days_remaining, "jour", "jours")} pour découvrir toutes les
        fonctionnalités.
      </Alert>
    );
  }

  return null;
}

function KpiStrip({ items }: { items: Kpi[] }) {
  const dense = items.length > 4;

  return (
    <div
      className={cn(
        "grid gap-px overflow-hidden rounded-xl border bg-border shadow-card",
        GRID_COLUMNS[items.length] ?? "grid-cols-2",
      )}
    >
      {items.map((item, index) => (
        <div
          key={item.label}
          className={cn(
            "bg-card p-5 sm:p-6",
            dense && index === items.length - 1 && "col-span-2 xl:col-span-1",
          )}
        >
          <div className="flex items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-accent text-muted-foreground">
              <item.icon className="size-[18px]" strokeWidth={1.5} aria-hidden />
            </span>
            <p className="text-sm text-muted-foreground">{item.label}</p>
          </div>

          <p
            className={cn(
              "mt-4 break-words font-semibold tabular-nums tracking-display",
              dense ? "text-2xl" : "text-2xl sm:text-3xl",
              item.tone ? VALUE_TONES[item.tone] : "text-foreground",
            )}
          >
            {item.value}
          </p>

          {item.hint ? <p className="mt-1 text-xs text-muted-foreground">{item.hint}</p> : null}
        </div>
      ))}
    </div>
  );
}

function AlertsCard({ alerts }: { alerts: Dashboard["alerts"] }) {
  return (
    <section className="rounded-xl border bg-card p-6 shadow-card">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-lg">Alertes</h2>
        <Link
          href="/alerts"
          className="text-sm font-medium text-primary underline-offset-4 hover:underline"
        >
          Tout voir
        </Link>
      </div>

      {alerts.total === 0 ? (
        <p className="mt-5 flex items-center gap-2.5 rounded-lg bg-success-soft p-4 text-sm text-success">
          <CircleCheck className="size-4 shrink-0" strokeWidth={1.5} aria-hidden />
          Tout est en ordre : aucune alerte.
        </p>
      ) : null}

      <ul className="mt-5 space-y-1">
        {alertOrder.map((type) => {
          const meta = alertMeta[type];
          const count = alerts[type];

          return (
            <li key={type}>
              <Link
                href={`/alerts?type=${type}`}
                className="flex items-center gap-3 rounded-md px-2 py-2.5 transition-colors duration-200 ease-smooth hover:bg-accent"
              >
                <span
                  className={cn(
                    "flex size-9 shrink-0 items-center justify-center rounded-md",
                    count > 0 ? toneClasses[meta.tone] : "bg-accent text-muted-foreground",
                  )}
                >
                  <meta.icon className="size-[18px]" strokeWidth={1.5} aria-hidden />
                </span>

                <span className={cn("flex-1 text-sm", count > 0 ? "font-medium text-foreground" : "text-muted-foreground")}>
                  {meta.label}
                </span>

                <span
                  className={cn(
                    "text-sm font-medium tabular-nums",
                    count > 0 ? "text-foreground" : "text-muted-foreground",
                  )}
                >
                  {count}
                </span>

                <ChevronRight className="size-4 text-muted-foreground" strokeWidth={1.5} aria-hidden />
              </Link>
            </li>
          );
        })}
      </ul>
    </section>
  );
}

function RecentEntries({
  entries,
  currency,
}: {
  entries: NonNullable<Dashboard["entries"]>;
  currency: string;
}) {
  return (
    <section className="rounded-xl border bg-card p-6 shadow-card">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-lg">Dernières entrées</h2>
        <Link
          href="/stock-entries"
          className="text-sm font-medium text-primary underline-offset-4 hover:underline"
        >
          Tout voir
        </Link>
      </div>

      {entries.recent.length === 0 ? (
        <p className="mt-5 text-sm text-muted-foreground">
          Aucune entrée pour le moment. Enregistrez votre première livraison pour alimenter le stock.
        </p>
      ) : (
        <ul className="mt-5 divide-y">
          {entries.recent.map((entry) => (
            <li key={entry.id}>
              <Link
                href={`/stock-entries/${entry.id}`}
                className="flex items-center justify-between gap-4 py-3.5 transition-colors duration-200 ease-smooth first:pt-0 last:pb-0 hover:text-foreground"
              >
                <div className="min-w-0 space-y-0.5">
                  <p className="truncate text-sm font-medium text-foreground">
                    {entry.number ?? "Brouillon"}
                    <span className="font-normal text-muted-foreground">
                      {" · "}
                      {entry.supplier?.name ?? entry.reason_label}
                    </span>
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {formatDate(entry.entry_date)} · {entry.warehouse.name}
                  </p>
                </div>

                <div className="flex shrink-0 flex-col items-end gap-1.5">
                  <span className="text-sm font-medium tabular-nums text-foreground">
                    {entry.total_amount ? formatMoney(entry.total_amount, currency) : "—"}
                  </span>
                  <EntryStatusBadge status={entry.status} label={entry.status_label} />
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function buildKpis(data: Dashboard, currency: string): Kpi[] {
  const items: Kpi[] = [
    {
      label: "Articles actifs",
      value: String(data.articles_active),
      hint: data.articles_active > 1 ? "dans votre catalogue" : "dans votre catalogue",
      icon: Package,
    },
  ];

  if (data.stock_value !== null) {
    items.push({
      label: "Valeur du stock",
      value: formatMoney(data.stock_value, currency),
      hint: "Au coût d'achat",
      icon: Coins,
    });
  } else {
    items.push({
      label: "Entrepôts actifs",
      value: String(data.warehouses_active),
      icon: Warehouse,
    });
  }

  if (data.entries) {
    items.push({
      label: "Entrées (30 jours)",
      value: formatMoney(data.entries.total_30d, currency),
      hint: pluralize(data.entries.count_30d, "entrée validée", "entrées validées"),
      icon: PackagePlus,
    });
  }

    if (data.exits) {
    items.push({
      label: "Sorties (30 jours)",
      value: formatMoney(data.exits.total_30d, currency),
      hint: `${pluralize(data.exits.count_30d, "sortie validée", "sorties validées")} · au coût d'achat`,
      icon: PackageMinus,
    });
  }

  const urgent = data.alerts.expired + data.alerts.out_of_stock > 0;

  items.push({
    label: "Alertes",
    value: String(data.alerts.total),
    hint: data.alerts.total === 0 ? "Tout est en ordre" : urgent ? "Action requise" : "À surveiller",
    icon: Bell,
    tone: data.alerts.total === 0 ? "success" : urgent ? "danger" : "warning",
  });

  return items;
}

export function DashboardHome() {
  const { session } = useSession();
  const membership = useActiveMembership();
  const query = useDashboard();

  if (session.status !== "authenticated") {
    return null;
  }

  const tenant = membership.tenant;

  return (
    <div className="space-y-8">
      <header className="space-y-1.5">
        <h1 className="text-3xl">Bonjour {firstName(session.me.user.name)}</h1>
        <p className="text-muted-foreground">Voici l&apos;état de {tenant.name}.</p>
      </header>

      <TrialNotice tenant={tenant} />

      {query.isPending ? (
        <div className="space-y-6">
          <Skeleton className="h-36 w-full rounded-xl" />
          <div className="grid gap-6 lg:grid-cols-5">
            <Skeleton className="h-72 rounded-xl lg:col-span-2" />
            <Skeleton className="h-72 rounded-xl lg:col-span-3" />
          </div>
        </div>
      ) : query.isError ? (
        <div className="space-y-4">
          <Alert variant="danger" title="Impossible de charger le tableau de bord">
            {query.error instanceof ApiError
              ? query.error.message
              : "Une erreur inattendue est survenue. Réessayez."}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : (
        <div className="space-y-6">
          <KpiStrip items={buildKpis(query.data, tenant.currency)} />

          <div className={cn("grid gap-6", query.data.entries && "lg:grid-cols-5")}>
            <div className={cn(query.data.entries && "lg:col-span-2")}>
              <AlertsCard alerts={query.data.alerts} />
            </div>

            {query.data.entries ? (
              <div className="lg:col-span-3">
                <RecentEntries entries={query.data.entries} currency={tenant.currency} />
              </div>
            ) : null}
          </div>
        </div>
      )}
    </div>
  );
}