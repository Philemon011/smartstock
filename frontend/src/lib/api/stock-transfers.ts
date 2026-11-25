import { apiRequest } from "./client";
import type { PaginationMeta } from "./pagination";
import type { StockEntryStatus } from "./stock-entries";

export type StockTransferAbilities = {
  update: boolean;
  delete: boolean;
  validate: boolean;
  cancel: boolean;
};

export type StockTransferAllocation = {
  id: string;
  source_lot: { id: string; code: string; lot_number: string | null; expiry_date: string | null };
  destination_lot: { id: string; code: string };
  quantity: string;
  /** Présents seulement pour les rôles qui voient les coûts. */
  unit_cost?: string;
  cost?: string;
};

export type StockTransferLine = {
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
  /** Lot demandé explicitement (null : le lot qui expire en premier). */
  lot: { id: string; code: string } | null;
  cost_total?: string | null;
  allocations: StockTransferAllocation[];
};

export type StockTransfer = {
  id: string;
  number: string | null;
  status: StockEntryStatus;
  status_label: string;
  transfer_date: string;
  from_warehouse: { id: string; name: string };
  to_warehouse: { id: string; name: string };
  reference: string | null;
  notes: string | null;
  /** Absent pour les rôles qui ne voient pas les coûts. */
  total_cost?: string | null;
  lines_count?: number;
  lines?: StockTransferLine[];
  created_by?: string | null;
  validated_at: string | null;
  validated_by?: string | null;
  cancelled_at: string | null;
  cancelled_by?: string | null;
  cancel_reason: string | null;
  can: StockTransferAbilities;
};

export type StockTransferList = {
  data: StockTransfer[];
  meta: PaginationMeta & { can: { create: boolean } };
};

export type StockTransferLineInput = {
  article_id: string;
  packaging_id: string | null;
  entered_quantity: string;
  lot_id: string | null;
};

export type StockTransferInput = {
  transfer_date: string;
  from_warehouse_id: string;
  to_warehouse_id: string;
  reference: string | null;
  notes: string | null;
  lines: StockTransferLineInput[];
};

export type StockTransferFilters = {
  search: string;
  status: "all" | StockEntryStatus;
  warehouseId: string | null;
  from: string;
  to: string;
  page: number;
};

const BASE = "/api/v1/stock-transfers";

export function listStockTransfers(
  filters: StockTransferFilters,
  signal?: AbortSignal,
): Promise<StockTransferList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);
  if (filters.warehouseId) params.set("warehouse_id", filters.warehouseId);
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.page > 1) params.set("page", String(filters.page));

  const query = params.toString();

  return apiRequest<StockTransferList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function getStockTransfer(id: string, signal?: AbortSignal): Promise<StockTransfer> {
  const response = await apiRequest<{ data: StockTransfer }>(`${BASE}/${id}`, { signal });

  return response.data;
}

export async function createStockTransfer(input: StockTransferInput): Promise<StockTransfer> {
  const response = await apiRequest<{ data: StockTransfer }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function updateStockTransfer(id: string, input: StockTransferInput): Promise<StockTransfer> {
  const response = await apiRequest<{ data: StockTransfer }>(`${BASE}/${id}`, {
    method: "PUT",
    body: input,
  });

  return response.data;
}

export function deleteStockTransfer(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}`, { method: "DELETE" });
}

export async function validateStockTransfer(id: string): Promise<StockTransfer> {
  const response = await apiRequest<{ data: StockTransfer }>(`${BASE}/${id}/validate`, {
    method: "POST",
  });

  return response.data;
}

export async function cancelStockTransfer(id: string, reason: string): Promise<StockTransfer> {
  const response = await apiRequest<{ data: StockTransfer }>(`${BASE}/${id}/cancel`, {
    method: "POST",
    body: { reason },
  });

  return response.data;
}