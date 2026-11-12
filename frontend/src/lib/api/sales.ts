import { apiRequest } from "./client";
import type { PaginationMeta } from "./pagination";
import type { StockEntryStatus } from "./stock-entries";

export type PaymentMethod = "cash" | "mtn_momo" | "moov_money" | "card" | "bank_transfer";
export type PaymentStatus = "paid" | "partial" | "unpaid";

export type SaleMeta = {
  payment_methods: { value: PaymentMethod; label: string; is_cash: boolean }[];
  payment_statuses: { value: PaymentStatus; label: string }[];
};

export type SalePayment = {
  id: string;
  method: PaymentMethod;
  method_label: string;
  amount: string;
  tendered: string | null;
  change_given: string;
  reference: string | null;
  paid_at: string;
  status: "valid" | "voided";
  voided_at: string | null;
  void_reason: string | null;
  created_by: string | null;
};

export type SaleAllocation = {
  id: string;
  lot: { id: string; code: string; lot_number: string | null; expiry_date: string | null };
  quantity: string;
  /** Présent seulement pour les rôles qui voient les coûts. */
  unit_cost?: string;
};

export type SaleLine = {
  id: string;
  article: {
    id: string;
    code: string;
    name: string;
    unit: { symbol: string; allows_decimals: boolean };
  };
  packaging_id: string | null;
  entered_unit: string;
  entered_quantity: string;
  quantity: string;
  list_price: string;
  unit_price: string;
  discount: { type: "percent" | "amount"; value: string; amount: string } | null;
  gross_total: string;
  line_total: string;
  cost_total?: string;
  allocations: SaleAllocation[];
};

export type SaleAbilities = {
  cancel: boolean;
  collect: boolean;
  void_payment: boolean;
};

export type Sale = {
  id: string;
  number: string;
  status: StockEntryStatus;
  status_label: string;
  sale_date: string;
  warehouse: { id: string; name: string };
  customer: { id: string; name: string; phone: string | null } | null;
  notes: string | null;
  subtotal: string;
  discount_total: string;
  total: string;
  amount_paid: string;
  balance_due: string;
  payment_status: PaymentStatus;
  payment_status_label: string;
  /** Présents seulement pour les rôles qui voient les coûts. */
  cost_total?: string;
  margin?: string;
  lines_count?: number;
  lines?: SaleLine[];
  payments?: SalePayment[];
  created_by?: string | null;
  created_at: string | null;
  cancelled_at: string | null;
  cancelled_by?: string | null;
  cancel_reason: string | null;
  can: SaleAbilities;
};

export type SaleList = {
  data: Sale[];
  meta: PaginationMeta & { can: { create: boolean } };
};

export type SaleLineInput = {
  article_id: string;
  packaging_id: string | null;
  entered_quantity: string;
  unit_price: string | null;
  discount_type: "percent" | "amount" | null;
  discount_value: string | null;
};

export type SalePaymentInput = {
  method: string;
  amount: string;
  reference: string | null;
};

export type SaleInput = {
  sale_date: string;
  warehouse_id: string;
  customer_id: string | null;
  notes: string | null;
  lines: SaleLineInput[];
  payments: SalePaymentInput[];
};

export type SaleFilters = {
  search: string;
  status: "all" | StockEntryStatus;
  paymentStatus: "all" | PaymentStatus;
customerId: string | null;
  warehouseId: string | null;
  hasBalance: boolean;
  from: string;
  to: string;
  page: number;
};

export type CustomerCollection = {
  customer_id: string;
  applied: string;
  change_given: string;
  outstanding_after: string;
  sales: { id: string; number: string; amount: string; payment_status: PaymentStatus }[];
};

const BASE = "/api/v1/sales";

export function getSaleMeta(signal?: AbortSignal): Promise<SaleMeta> {
  return apiRequest<SaleMeta>("/api/v1/meta/sales", { signal });
}

export function listSales(filters: SaleFilters, signal?: AbortSignal): Promise<SaleList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);
  if (filters.paymentStatus !== "all") params.set("payment_status", filters.paymentStatus);
  if (filters.customerId) params.set("customer_id", filters.customerId);
  if (filters.warehouseId) params.set("warehouse_id", filters.warehouseId);
  if (filters.hasBalance) params.set("has_balance", "1");
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.page > 1) params.set("page", String(filters.page));

  const query = params.toString();

  return apiRequest<SaleList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function getSale(id: string, signal?: AbortSignal): Promise<Sale> {
  const response = await apiRequest<{ data: Sale }>(`${BASE}/${id}`, { signal });

  return response.data;
}

export async function createSale(input: SaleInput): Promise<Sale> {
  const response = await apiRequest<{ data: Sale }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function cancelSale(id: string, reason: string): Promise<Sale> {
  const response = await apiRequest<{ data: Sale }>(`${BASE}/${id}/cancel`, {
    method: "POST",
    body: { reason },
  });

  return response.data;
}

export type CollectInput = { method: string; amount: string; reference: string | null };

export async function collectPayment(id: string, input: CollectInput): Promise<Sale> {
  const response = await apiRequest<{ data: Sale }>(`${BASE}/${id}/payments`, {
    method: "POST",
    body: input,
  });

  return response.data;
}

export async function voidPayment(saleId: string, paymentId: string, reason: string): Promise<Sale> {
  const response = await apiRequest<{ data: Sale }>(`${BASE}/${saleId}/payments/${paymentId}/void`, {
    method: "POST",
    body: { reason },
  });

  return response.data;
}

export async function collectForCustomer(
  customerId: string,
  input: CollectInput,
): Promise<CustomerCollection> {
  const response = await apiRequest<{ data: CustomerCollection }>(
    `/api/v1/customers/${customerId}/payments`,
    { method: "POST", body: input },
  );

  return response.data;
}