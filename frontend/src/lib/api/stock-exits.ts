import { apiRequest } from "./client";
import type { PaginationMeta } from "./pagination";
import type { StockEntryStatus } from "./stock-entries";

export type StockExitReason =
  | "loss"
  | "breakage"
  | "expiry"
  | "internal_use"
  | "donation"
  | "supplier_return"
  | "other";

export type StockExitAbilities = {
  update: boolean;
  delete: boolean;
  validate: boolean;
  cancel: boolean;
};

export type StockExitAllocation = {
  id: string;
  lot: {
    id: string;
    code: string;
    lot_number: string | null;
    expiry_date: string | null;
  };
  quantity: string;
  unit_cost: string;
  value: string;
};

export type StockExitLine = {
  id: string;
  article: {
    id: string;
    code: string;
    name: string;
    unit: { symbol: string; allows_decimals: boolean };
  };
  packaging_id: string | null;
  entered_unit: string;
  factor: string;
  entered_quantity: string;
  quantity: string;
  /** Lot demandé explicitement (null : prélèvement automatique). */
  lot: { id: string; code: string } | null;
  /** Renseigné à la validation. */
  cost_total: string | null;
  allocations: StockExitAllocation[];
};

export type StockExit = {
  id: string;
  number: string | null;
  status: StockEntryStatus;
  status_label: string;
  reason: StockExitReason;
  reason_label: string;
  exit_date: string;
  warehouse: { id: string; name: string };
  supplier: { id: string; name: string } | null;
  reference: string | null;
  notes: string | null;
  total_cost: string | null;
  lines_count?: number;
  lines?: StockExitLine[];
  created_by?: string | null;
  validated_at: string | null;
  validated_by?: string | null;
  cancelled_at: string | null;
  cancelled_by?: string | null;
  cancel_reason: string | null;
  can: StockExitAbilities;
};

export type StockExitList = {
  data: StockExit[];
  meta: PaginationMeta;
};

export type StockExitLineInput = {
  article_id: string;
  packaging_id: string | null;
  entered_quantity: string;
  lot_id: string | null;
};

export type StockExitInput = {
  reason: string;
  exit_date: string;
  warehouse_id: string;
  supplier_id: string | null;
  reference: string | null;
  notes: string | null;
  lines: StockExitLineInput[];
};

export type StockExitFilters = {
  search: string;
  status: "all" | StockEntryStatus;
  reason: "all" | StockExitReason;
  warehouseId: string | null;
  from: string;
  to: string;
  page: number;
};

const BASE = "/api/v1/stock-exits";

export function listStockExits(filters: StockExitFilters, signal?: AbortSignal): Promise<StockExitList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);
  if (filters.reason !== "all") params.set("reason", filters.reason);
  if (filters.warehouseId) params.set("warehouse_id", filters.warehouseId);
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.page > 1) params.set("page", String(filters.page));

  const query = params.toString();

  return apiRequest<StockExitList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function getStockExit(id: string, signal?: AbortSignal): Promise<StockExit> {
  const response = await apiRequest<{ data: StockExit }>(`${BASE}/${id}`, { signal });

  return response.data;
}

export async function createStockExit(input: StockExitInput): Promise<StockExit> {
  const response = await apiRequest<{ data: StockExit }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function updateStockExit(id: string, input: StockExitInput): Promise<StockExit> {
  const response = await apiRequest<{ data: StockExit }>(`${BASE}/${id}`, {
    method: "PUT",
    body: input,
  });

  return response.data;
}

export function deleteStockExit(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}`, { method: "DELETE" });
}

export async function validateStockExit(id: string): Promise<StockExit> {
  const response = await apiRequest<{ data: StockExit }>(`${BASE}/${id}/validate`, {
    method: "POST",
  });

  return response.data;
}

export async function cancelStockExit(id: string, reason: string): Promise<StockExit> {
  const response = await apiRequest<{ data: StockExit }>(`${BASE}/${id}/cancel`, {
    method: "POST",
    body: { reason },
  });

  return response.data;
}