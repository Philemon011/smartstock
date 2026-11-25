"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import {
  cancelStockTransfer,
  createStockTransfer,
  deleteStockTransfer,
  getStockTransfer,
  listStockTransfers,
  updateStockTransfer,
  validateStockTransfer,
  type StockTransferFilters,
  type StockTransferInput,
} from "@/lib/api/stock-transfers";

export function useStockTransfers(filters: StockTransferFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["stock-transfers", tenant.id, "list", filters],
    queryFn: ({ signal }) => listStockTransfers(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useStockTransfer(id: string) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["stock-transfers", tenant.id, "detail", id],
    queryFn: ({ signal }) => getStockTransfer(id, signal),
  });
}

export function useStockTransferMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidateTransfers = () => queryClient.invalidateQueries({ queryKey: ["stock-transfers", tenant.id] });

  // Valider ou annuler un transfert déplace le stock : fiches articles, entrepôts, alertes et listes de stock.
  const invalidateStock = async () => {
    await Promise.all([
      invalidateTransfers(),
      queryClient.invalidateQueries({ queryKey: ["articles", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["warehouses", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["alerts", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["dashboard", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["stock-articles", tenant.id] }),
    ]);
  };

  return {
    create: useMutation({
      mutationFn: (input: StockTransferInput) => createStockTransfer(input),
      onSuccess: invalidateTransfers,
    }),
    update: useMutation({
      mutationFn: ({ id, input }: { id: string; input: StockTransferInput }) => updateStockTransfer(id, input),
      onSuccess: invalidateTransfers,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteStockTransfer(id),
      onSuccess: invalidateTransfers,
    }),
    validate: useMutation({
      mutationFn: (id: string) => validateStockTransfer(id),
      onSuccess: invalidateStock,
    }),
    cancel: useMutation({
      mutationFn: ({ id, reason }: { id: string; reason: string }) => cancelStockTransfer(id, reason),
      onSuccess: invalidateStock,
    }),
  };
}