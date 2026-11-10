import { apiRequest } from "./client";
import type { PaginationMeta } from "./pagination";

export type AlertType = "expired" | "expiring" | "low_stock" | "out_of_stock";

export type AlertSummary = Record<AlertType, number> & { total: number };

export type AlertRow = {
  id: string;
  article: {
    id: string;
    code: string;
    name: string;
    unit: { symbol: string; allows_decimals: boolean };
  };
  lot: {
    id: string;
    code: string;
    lot_number: string | null;
    warehouse: string;
    expiry_date: string;
    days_to_expiry: number;
    quantity: string;
    /** Présent seulement pour les rôles qui ont le droit de voir les coûts. */
    value?: string;
  } | null;
  stock: { quantity: string; minimum: string; ever_stocked: boolean } | null;
};

export type AlertList = {
  data: AlertRow[];
  meta: PaginationMeta;
};

export async function getAlertSummary(signal?: AbortSignal): Promise<AlertSummary> {
  const response = await apiRequest<{ data: AlertSummary }>("/api/v1/alerts/summary", { signal });

  return response.data;
}

export function listAlerts(type: AlertType, page: number, signal?: AbortSignal): Promise<AlertList> {
  const params = new URLSearchParams({ type });

  if (page > 1) params.set("page", String(page));

  return apiRequest<AlertList>(`/api/v1/alerts?${params.toString()}`, { signal });
}