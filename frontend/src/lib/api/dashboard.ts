import type { AlertSummary } from "./alerts";
import { apiRequest } from "./client";
import type { StockEntry } from "./stock-entries";

export type Dashboard = {
  articles_active: number;
  warehouses_active: number;
  alerts: AlertSummary;
  /** Null pour les rôles qui n'ont pas le droit de voir les coûts. */
  stock_value: string | null;
  /** Null pour les rôles qui ne gèrent pas le stock. */
  entries: {
    count_30d: number;
    total_30d: string;
    recent: StockEntry[];
  } | null;

    /** Null pour les rôles qui ne gèrent pas le stock. */
  exits: {
    count_30d: number;
    total_30d: string;
  } | null;
};

export async function getDashboard(signal?: AbortSignal): Promise<Dashboard> {
  const response = await apiRequest<{ data: Dashboard }>("/api/v1/dashboard", { signal });

  return response.data;
}