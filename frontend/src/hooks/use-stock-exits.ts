"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import {
  cancelStockExit,
  createStockExit,
  deleteStockExit,
  getStockExit,
  listStockExits,
  updateStockExit,
  validateStockExit,
  type StockExitFilters,
  type StockExitInput,
} from "@/lib/api/stock-exits";

export function useStockExits(filters: StockExitFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["stock-exits", tenant.id, "list", filters],
    queryFn: ({ signal }) => listStockExits(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useStockExit(id: string) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["stock-exits", tenant.id, "detail", id],
    queryFn: ({ signal }) => getStockExit(id, signal),
  });
}

export function useStockExitMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidateExits = () => queryClient.invalidateQueries({ queryKey: ["stock-exits", tenant.id] });

  // Valider ou annuler une sortie change le stock, les alertes et le tableau de bord.
  const invalidateStock = async () => {
    await Promise.all([
      invalidateExits(),
      queryClient.invalidateQueries({ queryKey: ["stock-entries", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["articles", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["warehouses", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["alerts", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["dashboard", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["stock-articles", tenant.id] }),
    ]);
  };

  return {
    create: useMutation({
      mutationFn: (input: StockExitInput) => createStockExit(input),
      onSuccess: invalidateExits,
    }),
    update: useMutation({
      mutationFn: ({ id, input }: { id: string; input: StockExitInput }) => updateStockExit(id, input),
      onSuccess: invalidateExits,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteStockExit(id),
      onSuccess: invalidateExits,
    }),
    validate: useMutation({
      mutationFn: (id: string) => validateStockExit(id),
      onSuccess: invalidateStock,
    }),
    cancel: useMutation({
      mutationFn: ({ id, reason }: { id: string; reason: string }) => cancelStockExit(id, reason),
      onSuccess: invalidateStock,
    }),
  };
}