import type { Article } from "./articles";
import { apiRequest } from "./client";
import { formatQuantity } from "@/lib/format";

/** Un article du catalogue avec sa quantité disponible dans l'entrepôt demandé. */
export type AvailableArticle = Article & { available: string };

export type StockMode = "sellable" | "expired";

export async function listAvailableArticles(
  warehouseId: string,
  mode: StockMode,
  search: string,
  signal?: AbortSignal,
): Promise<AvailableArticle[]> {
  const params = new URLSearchParams({ warehouse_id: warehouseId, mode });

  if (search) params.set("search", search);

  const response = await apiRequest<{ data: AvailableArticle[] }>(`/api/v1/stock/articles?${params.toString()}`, {
    signal,
  });

  return response.data;
}

/** La quantité disponible n'est valable qu'au moment du choix : on ne la garde pas sur la ligne. */
export function toArticle(item: Article | AvailableArticle): Article {
  const article: Partial<AvailableArticle> = { ...item };

  delete article.available;

  return article as Article;
}

/** « ART-000012 · 8 sac en stock » */
export function stockHint(item: Article | AvailableArticle): string {
  return "available" in item
    ? `${item.code} · ${formatQuantity(item.available)} ${item.unit.symbol} en stock`
    : `${item.code} · ${item.unit.symbol}`;
}