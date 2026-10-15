import { apiRequest } from "./client";

export type WarehouseAbilities = {
  update: boolean;
  delete: boolean;
  toggle: boolean;
  set_main: boolean;
};

export type Warehouse = {
  id: string;
  name: string;
  code: string | null;
  address: string | null;
  city: string | null;
  phone: string | null;
  is_main: boolean;
  is_active: boolean;
  created_at: string | null;
  can: WarehouseAbilities;
};

export type WarehouseList = {
  data: Warehouse[];
  meta: { can: { create: boolean } };
};

export type WarehouseInput = {
  name: string;
  code: string | null;
  address: string | null;
  city: string | null;
  phone: string | null;
};

export type WarehouseStatusFilter = "all" | "active" | "inactive";

export type WarehouseFilters = {
  search: string;
  status: WarehouseStatusFilter;
};

const BASE = "/api/v1/warehouses";

export function listWarehouses(filters: WarehouseFilters, signal?: AbortSignal): Promise<WarehouseList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);

  const query = params.toString();

  return apiRequest<WarehouseList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function createWarehouse(input: WarehouseInput): Promise<Warehouse> {
  const response = await apiRequest<{ data: Warehouse }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function updateWarehouse(id: string, input: WarehouseInput): Promise<Warehouse> {
  const response = await apiRequest<{ data: Warehouse }>(`${BASE}/${id}`, {
    method: "PUT",
    body: input,
  });

  return response.data;
}

export function deleteWarehouse(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}`, { method: "DELETE" });
}

async function runAction(id: string, action: "set-main" | "activate" | "deactivate"): Promise<Warehouse> {
  const response = await apiRequest<{ data: Warehouse }>(`${BASE}/${id}/${action}`, {
    method: "POST",
  });

  return response.data;
}

export const setMainWarehouse = (id: string) => runAction(id, "set-main");
export const activateWarehouse = (id: string) => runAction(id, "activate");
export const deactivateWarehouse = (id: string) => runAction(id, "deactivate");