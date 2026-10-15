import { apiRequest } from "./client";

export type CategoryAbilities = {
  update: boolean;
  delete: boolean;
  toggle: boolean;
  add_child: boolean;
};

export type Category = {
  id: string;
  name: string;
  description: string | null;
  parent_id: string | null;
  is_active: boolean;
  depth: 0 | 1;
  children_count: number;
  created_at: string | null;
  can: CategoryAbilities;
};

export type CategoryList = {
  data: Category[];
  meta: { can: { create: boolean } };
};

export type CategoryInput = {
  name: string;
  description: string | null;
  parent_id: string | null;
};

export type CategoryStatusFilter = "all" | "active" | "inactive";

export type CategoryFilters = {
  search: string;
  status: CategoryStatusFilter;
};

const BASE = "/api/v1/categories";

export function listCategories(filters: CategoryFilters, signal?: AbortSignal): Promise<CategoryList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);

  const query = params.toString();

  return apiRequest<CategoryList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function createCategory(input: CategoryInput): Promise<Category> {
  const response = await apiRequest<{ data: Category }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function updateCategory(id: string, input: CategoryInput): Promise<Category> {
  const response = await apiRequest<{ data: Category }>(`${BASE}/${id}`, {
    method: "PUT",
    body: input,
  });

  return response.data;
}

export function deleteCategory(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}`, { method: "DELETE" });
}

async function runAction(id: string, action: "activate" | "deactivate"): Promise<Category> {
  const response = await apiRequest<{ data: Category }>(`${BASE}/${id}/${action}`, {
    method: "POST",
  });

  return response.data;
}

export const activateCategory = (id: string) => runAction(id, "activate");
export const deactivateCategory = (id: string) => runAction(id, "deactivate");