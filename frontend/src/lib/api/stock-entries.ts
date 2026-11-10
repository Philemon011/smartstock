import { apiRequest } from "./client";
import type { PaginationMeta } from "./pagination";

export type StockEntryStatus = "draft" | "validated" | "cancelled";
export type StockEntryReason =
  | "purchase"
  | "customer_return"
  | "donation"
  | "opening_stock"
  | "other";

export type StockEntryAbilities = {
  update: boolean;
  delete: boolean;
  validate: boolean;
  cancel: boolean;
  document: boolean;
};

export type StockEntryLine = {
  id: string;
  position: number;
  article: {
    id: string;
    code: string;
    name: string;
    track_expiry: boolean;
    unit: { symbol: string; allows_decimals: boolean };
  };
  packaging_id: string | null;
  entered_unit: string;
  factor: string;
  entered_quantity: string;
  quantity: string;
  unit_price: string;
  line_total: string;
  expiry_date: string | null;
  lot_number: string | null;
  sale_price: string | null;
  lot: { id: string; code: string } | null;
};

export type StockEntry = {
  id: string;
  number: string | null;
  status: StockEntryStatus;
  status_label: string;
  reason: StockEntryReason;
  reason_label: string;
  entry_date: string;
  warehouse: { id: string; name: string };
  supplier: { id: string; name: string } | null;
  reference: string | null;
  notes: string | null;
  document: { name: string | null; url: string } | null;
  total_amount: string | null;
  lines_count?: number;
  lines?: StockEntryLine[];
  created_by?: string | null;
  validated_at: string | null;
  validated_by?: string | null;
  cancelled_at: string | null;
  cancelled_by?: string | null;
  cancel_reason: string | null;
  created_at: string | null;
  can: StockEntryAbilities;
};

export type StockEntryList = {
  data: StockEntry[];
  meta: PaginationMeta & { can: { create: boolean } };
};

export type StockEntryLineInput = {
  article_id: string;
  packaging_id: string | null;
  entered_quantity: string;
  unit_price: string;
  expiry_date: string | null;
  lot_number: string | null;
  sale_price: string | null;
};

export type StockEntryInput = {
  reason: string;
  entry_date: string;
  warehouse_id: string;
  supplier_id: string | null;
  reference: string | null;
  notes: string | null;
  lines: StockEntryLineInput[];
};

export type StockEntryFilters = {
  search: string;
  status: "all" | StockEntryStatus;
  reason: "all" | StockEntryReason;
  warehouseId: string | null;
  from: string;
  to: string;
  page: number;
};

export type StockMeta = {
  entry_reasons: {
    value: StockEntryReason;
    label: string;
    requires_supplier: boolean;
    allows_zero_price: boolean;
  }[];
    entry_statuses: { value: StockEntryStatus; label: string }[];
  exit_reasons: {
    value: string;
    label: string;
    requires_supplier: boolean;
    requires_notes: boolean;
    expired_only: boolean;
  }[];
};

const BASE = "/api/v1/stock-entries";

export function getStockMeta(signal?: AbortSignal): Promise<StockMeta> {
  return apiRequest<StockMeta>("/api/v1/meta/stock", { signal });
}

export function listStockEntries(
  filters: StockEntryFilters,
  signal?: AbortSignal,
): Promise<StockEntryList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);
  if (filters.reason !== "all") params.set("reason", filters.reason);
  if (filters.warehouseId) params.set("warehouse_id", filters.warehouseId);
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.page > 1) params.set("page", String(filters.page));

  const query = params.toString();

  return apiRequest<StockEntryList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function getStockEntry(id: string, signal?: AbortSignal): Promise<StockEntry> {
  const response = await apiRequest<{ data: StockEntry }>(`${BASE}/${id}`, { signal });

  return response.data;
}

export async function createStockEntry(input: StockEntryInput): Promise<StockEntry> {
  const response = await apiRequest<{ data: StockEntry }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function updateStockEntry(id: string, input: StockEntryInput): Promise<StockEntry> {
  const response = await apiRequest<{ data: StockEntry }>(`${BASE}/${id}`, {
    method: "PUT",
    body: input,
  });

  return response.data;
}

export function deleteStockEntry(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}`, { method: "DELETE" });
}

export async function validateStockEntry(id: string): Promise<StockEntry> {
  const response = await apiRequest<{ data: StockEntry }>(`${BASE}/${id}/validate`, {
    method: "POST",
  });

  return response.data;
}

export async function cancelStockEntry(id: string, reason: string): Promise<StockEntry> {
  const response = await apiRequest<{ data: StockEntry }>(`${BASE}/${id}/cancel`, {
    method: "POST",
    body: { reason },
  });

  return response.data;
}

export async function uploadEntryDocument(id: string, file: File): Promise<StockEntry> {
  const form = new FormData();
  form.append("document", file);

  const response = await apiRequest<{ data: StockEntry }>(`${BASE}/${id}/document`, {
    method: "POST",
    body: form,
  });

  return response.data;
}

export function removeEntryDocument(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}/document`, { method: "DELETE" });
}