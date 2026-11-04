import { apiRequest } from "./client";
import type { PaginationMeta } from "./pagination";

export type ArticleAbilities = {
  update: boolean;
  delete: boolean;
  toggle: boolean;
};

export type ArticlePackaging = {
  id: string;
  name: string;
  quantity: string;
};

export type Article = {
  id: string;
  code: string;
  barcode: string | null;
  name: string;
  description: string | null;
  category: { id: string; name: string } | null;
  unit: { id: string; name: string; symbol: string; allows_decimals: boolean };
  sale_price: string;
  min_stock: string;
  track_expiry: boolean;
  expiry_alert_days: number;
  image_url: string | null;
  packagings: ArticlePackaging[];
  is_active: boolean;
  created_at: string | null;
  can: ArticleAbilities;
};

export type ArticleList = {
  data: Article[];
  meta: PaginationMeta & { can: { create: boolean } };
};

export type PackagingInput = {
  id: string | null;
  name: string;
  quantity: string;
};

export type ArticleInput = {
  name: string;
  description: string | null;
  barcode: string | null;
  category_id: string | null;
  unit_id: string;
  sale_price: string;
  min_stock: string;
  track_expiry: boolean;
  expiry_alert_days: number | string;
  packagings: PackagingInput[];
};

export type PriceChange = {
  id: string;
  old_price: string | null;
  new_price: string;
  changed_by: string | null;
  created_at: string | null;
};

export type ArticleStatusFilter = "all" | "active" | "inactive";

export type ArticleFilters = {
  search: string;
  status: ArticleStatusFilter;
  categoryId: string | null;
  page: number;
};

const BASE = "/api/v1/articles";

export function listArticles(filters: ArticleFilters, signal?: AbortSignal): Promise<ArticleList> {
  const params = new URLSearchParams();

  if (filters.search) params.set("search", filters.search);
  if (filters.status !== "all") params.set("status", filters.status);
  if (filters.categoryId) params.set("category_id", filters.categoryId);
  if (filters.page > 1) params.set("page", String(filters.page));

  const query = params.toString();

  return apiRequest<ArticleList>(query ? `${BASE}?${query}` : BASE, { signal });
}

export async function getArticle(id: string, signal?: AbortSignal): Promise<Article> {
  const response = await apiRequest<{ data: Article }>(`${BASE}/${id}`, { signal });

  return response.data;
}

export async function getPriceHistory(id: string, signal?: AbortSignal): Promise<PriceChange[]> {
  const response = await apiRequest<{ data: PriceChange[] }>(`${BASE}/${id}/price-history`, { signal });

  return response.data;
}

export async function createArticle(input: ArticleInput): Promise<Article> {
  const response = await apiRequest<{ data: Article }>(BASE, { method: "POST", body: input });

  return response.data;
}

export async function updateArticle(id: string, input: ArticleInput): Promise<Article> {
  const response = await apiRequest<{ data: Article }>(`${BASE}/${id}`, {
    method: "PUT",
    body: input,
  });

  return response.data;
}

export function deleteArticle(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}`, { method: "DELETE" });
}

async function runAction(id: string, action: "activate" | "deactivate"): Promise<Article> {
  const response = await apiRequest<{ data: Article }>(`${BASE}/${id}/${action}`, {
    method: "POST",
  });

  return response.data;
}

export const activateArticle = (id: string) => runAction(id, "activate");
export const deactivateArticle = (id: string) => runAction(id, "deactivate");

export async function uploadArticleImage(id: string, file: File): Promise<Article> {
  const form = new FormData();
  form.append("image", file);

  const response = await apiRequest<{ data: Article }>(`${BASE}/${id}/image`, {
    method: "POST",
    body: form,
  });

  return response.data;
}

export function removeArticleImage(id: string): Promise<null> {
  return apiRequest<null>(`${BASE}/${id}/image`, { method: "DELETE" });
}