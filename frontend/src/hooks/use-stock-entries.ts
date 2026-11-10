"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import { getArticleStock } from "@/lib/api/article-stock";
import {
  cancelStockEntry,
  createStockEntry,
  deleteStockEntry,
  getStockEntry,
  getStockMeta,
  listStockEntries,
  removeEntryDocument,
  updateStockEntry,
  uploadEntryDocument,
  validateStockEntry,
  type StockEntryFilters,
  type StockEntryInput,
} from "@/lib/api/stock-entries";

export function useStockMeta() {
  return useQuery({
    queryKey: ["stock-meta"],
    queryFn: ({ signal }) => getStockMeta(signal),
    staleTime: Infinity,
  });
}

export function useStockEntries(filters: StockEntryFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["stock-entries", tenant.id, "list", filters],
    queryFn: ({ signal }) => listStockEntries(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useStockEntry(id: string) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["stock-entries", tenant.id, "detail", id],
    queryFn: ({ signal }) => getStockEntry(id, signal),
  });
}

export function useArticleStock(articleId: string) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["articles", tenant.id, "stock", articleId],
    queryFn: ({ signal }) => getArticleStock(articleId, signal),
  });
}

export function useStockEntryMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidateEntries = () =>
    queryClient.invalidateQueries({ queryKey: ["stock-entries", tenant.id] });

  // Valider ou annuler une entrée change le stock et peut changer le prix de vente des articles.
    const invalidateStock = async () => {
    await Promise.all([
      invalidateEntries(),
      queryClient.invalidateQueries({ queryKey: ["articles", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["warehouses", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["alerts", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["dashboard", tenant.id] }),
    ]);
  };

  return {
    create: useMutation({
      mutationFn: (input: StockEntryInput) => createStockEntry(input),
      onSuccess: invalidateEntries,
    }),
    update: useMutation({
      mutationFn: ({ id, input }: { id: string; input: StockEntryInput }) =>
        updateStockEntry(id, input),
      onSuccess: invalidateEntries,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteStockEntry(id),
      onSuccess: invalidateEntries,
    }),
    validate: useMutation({
      mutationFn: (id: string) => validateStockEntry(id),
      onSuccess: invalidateStock,
    }),
    cancel: useMutation({
      mutationFn: ({ id, reason }: { id: string; reason: string }) => cancelStockEntry(id, reason),
      onSuccess: invalidateStock,
    }),
    uploadDocument: useMutation({
      mutationFn: ({ id, file }: { id: string; file: File }) => uploadEntryDocument(id, file),
      onSuccess: invalidateEntries,
    }),
    removeDocument: useMutation({
      mutationFn: (id: string) => removeEntryDocument(id),
      onSuccess: invalidateEntries,
    }),
  };
}