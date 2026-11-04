import { apiRequest } from "./client";
import type { PaginationMeta } from "./pagination";

export type SupplierAbilities = {
  update: boolean;
  delete: boolean;
  toggle: boolean;
};

export type Supplier = {
  id: string;
  name: string;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  notes: string | null;
  is_active: boolean;
  created_at: string | null;
  can: SupplierAbilities;
};

export type SupplierList = {
  data: Supplier[];
  meta: PaginationMeta & { can: { create: boolean } };
};

export type SupplierInput = {
  name: string;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  notes: string | null;
};

export type SupplierStatusFilter = "all" | "active" | "inactive";

export type SupplierFilters = {
  search: string;
  status: SupplierStatusFilter;
  page: number;
};

const BASE = "/api/v1/suppliers";

export function listSuppliers(filters: SupplierFilters, signal?: AbortSignal): Promise<SupplierList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);
  if (filters.page > 1) params.set("page", String(filters.page));

  const query = params.toString();

  return apiRequest<SupplierList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function createSupplier(input: SupplierInput): Promise<Supplier> {
  const response = await apiRequest<{ data: Supplier }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function updateSupplier(id: string, input: SupplierInput): Promise<Supplier> {
  const response = await apiRequest<{ data: Supplier }>(`${BASE}/${id}`, {
    method: "PUT",
    body: input,
  });

  return response.data;
}

export function deleteSupplier(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}`, { method: "DELETE" });
}

async function runAction(id: string, action: "activate" | "deactivate"): Promise<Supplier> {
  const response = await apiRequest<{ data: Supplier }>(`${BASE}/${id}/${action}`, {
    method: "POST",
  });

  return response.data;
}

export const activateSupplier = (id: string) => runAction(id, "activate");
export const deactivateSupplier = (id: string) => runAction(id, "deactivate");