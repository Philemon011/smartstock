import { apiRequest } from "./client";
import type { PaginationMeta } from "./pagination";

export type CustomerAbilities = {
  update: boolean;
  delete: boolean;
  toggle: boolean;
  set_credit_limit: boolean;
};

export type Customer = {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  notes: string | null;
  /** Null : pas de plafond. "0" : aucun crédit autorisé. */
  credit_limit: string | null;
    /** Ce que le client doit sur ses ventes validées. */
  balance_due: string | null;
  is_active: boolean;
  created_at: string | null;
  can: CustomerAbilities;
};

export type CustomerList = {
  data: Customer[];
  meta: PaginationMeta & { can: { create: boolean } };
};

export type CustomerInput = {
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  notes: string | null;
  /** Absent pour les rôles qui n'ont pas le droit de définir le plafond. */
  credit_limit?: string | null;
  
};

export type CustomerStatusFilter = "all" | "active" | "inactive";

export type CustomerFilters = {
  search: string;
  status: CustomerStatusFilter;
  page: number;
};

const BASE = "/api/v1/customers";

export function listCustomers(filters: CustomerFilters, signal?: AbortSignal): Promise<CustomerList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);
  if (filters.page > 1) params.set("page", String(filters.page));

  const query = params.toString();

  return apiRequest<CustomerList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function createCustomer(input: CustomerInput): Promise<Customer> {
  const response = await apiRequest<{ data: Customer }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function updateCustomer(id: string, input: CustomerInput): Promise<Customer> {
  const response = await apiRequest<{ data: Customer }>(`${BASE}/${id}`, {
    method: "PUT",
    body: input,
  });

  return response.data;
}

export function deleteCustomer(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}`, { method: "DELETE" });
}

async function runAction(id: string, action: "activate" | "deactivate"): Promise<Customer> {
  const response = await apiRequest<{ data: Customer }>(`${BASE}/${id}/${action}`, {
    method: "POST",
  });

  return response.data;
}

export const activateCustomer = (id: string) => runAction(id, "activate");
export const deactivateCustomer = (id: string) => runAction(id, "deactivate");