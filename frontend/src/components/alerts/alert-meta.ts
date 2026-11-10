import { CalendarX, Hourglass, PackageX, TrendingDown, type LucideIcon } from "lucide-react";
import type { AlertType } from "@/lib/api/alerts";

export type AlertMeta = {
  label: string;
  icon: LucideIcon;
  tone: "danger" | "warning";
  emptyTitle: string;
  emptyText: string;
};

export const alertMeta: Record<AlertType, AlertMeta> = {
  expired: {
    label: "Expirés",
    icon: CalendarX,
    tone: "danger",
    emptyTitle: "Aucun lot expiré",
    emptyText: "Tous vos lots en stock sont encore valables.",
  },
  expiring: {
    label: "Expirent bientôt",
    icon: Hourglass,
    tone: "warning",
    emptyTitle: "Aucune expiration proche",
    emptyText: "Aucun lot n'expire dans le délai d'alerte de son article.",
  },
  out_of_stock: {
    label: "Ruptures",
    icon: PackageX,
    tone: "danger",
    emptyTitle: "Aucune rupture",
    emptyText: "Tous vos articles suivis ont du stock.",
  },
  low_stock: {
    label: "Stock bas",
    icon: TrendingDown,
    tone: "warning",
    emptyTitle: "Aucun stock bas",
    emptyText: "Tous vos articles sont au-dessus de leur seuil d'alerte.",
  },
};

export const alertOrder: AlertType[] = ["expired", "expiring", "out_of_stock", "low_stock"];

export const toneClasses = {
  danger: "bg-danger-soft text-danger",
  warning: "bg-warning-soft text-warning",
  success: "bg-success-soft text-success",
} as const;

export function isAlertType(value: string | undefined): value is AlertType {
  return value !== undefined && value in alertMeta;
}