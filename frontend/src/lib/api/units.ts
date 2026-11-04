import { apiRequest } from "./client";

export type UnitAbilities = {
  update: boolean;
  delete: boolean;
  toggle: boolean;
};

export type Unit = {
  id: string;
  name: string;
  symbol: string;
  allows_decimals: boolean;
  is_active: boolean;
  created_at: string | null;
  can: UnitAbilities;
};

export type UnitList = {
  data: Unit[];
  meta: { can: { create: boolean } };
};

export type UnitInput = {
  name: string;
  symbol: string;
  allows_decimals: boolean;
};

export type UnitStatusFilter = "all" | "active" | "inactive";

export type UnitFilters = {
  search: string;
  status: UnitStatusFilter;
};

const BASE = "/api/v1/units";

export function listUnits(filters: UnitFilters, signal?: AbortSignal): Promise<UnitList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);

  const query = params.toString();

  return apiRequest<UnitList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function createUnit(input: UnitInput): Promise<Unit> {
  const response = await apiRequest<{ data: Unit }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function updateUnit(id: string, input: UnitInput): Promise<Unit> {
  const response = await apiRequest<{ data: Unit }>(`${BASE}/${id}`, {
    method: "PUT",
    body: input,
  });

  return response.data;
}

export function deleteUnit(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}`, { method: "DELETE" });
}

async function runAction(id: string, action: "activate" | "deactivate"): Promise<Unit> {
  const response = await apiRequest<{ data: Unit }>(`${BASE}/${id}/${action}`, {
    method: "POST",
  });

  return response.data;
}

export const activateUnit = (id: string) => runAction(id, "activate");
export const deactivateUnit = (id: string) => runAction(id, "deactivate");