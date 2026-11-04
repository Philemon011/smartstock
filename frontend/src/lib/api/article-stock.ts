import { apiRequest } from "./client";

export type StockLot = {
  id: string;
  code: string;
  lot_number: string | null;
  warehouse: { id: string; name: string };
  received_date: string;
  expiry_date: string | null;
  days_to_expiry: number | null;
  quantity_initial: string;
  quantity_remaining: string;
  /** Présents seulement pour les rôles qui ont le droit de voir les coûts. */
  unit_cost?: string;
  value?: string;
};

export type ArticleStock = {
  article_id: string;
  unit: { symbol: string; allows_decimals: boolean };
  total: string;
  warehouses: { warehouse: { id: string; name: string }; quantity: string }[];
  lots: StockLot[];
};

export async function getArticleStock(id: string, signal?: AbortSignal): Promise<ArticleStock> {
  const response = await apiRequest<{ data: ArticleStock }>(`/api/v1/articles/${id}/stock`, {
    signal,
  });

  return response.data;
}