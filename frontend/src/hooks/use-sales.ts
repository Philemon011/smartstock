"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import {
  cancelSale,
  collectForCustomer,
  collectPayment,
  createSale,
  getSale,
  getSaleMeta,
  listSales,
  voidPayment,
  type CollectInput,
  type SaleFilters,
  type SaleInput,
} from "@/lib/api/sales";

export function useSaleMeta() {
  return useQuery({
    queryKey: ["sale-meta"],
    queryFn: ({ signal }) => getSaleMeta(signal),
    staleTime: Infinity,
  });
}

export function useSales(filters: SaleFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["sales", tenant.id, "list", filters],
    queryFn: ({ signal }) => listSales(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useSale(id: string) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["sales", tenant.id, "detail", id],
    queryFn: ({ signal }) => getSale(id, signal),
  });
}

export function useSaleMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidateSales = () => queryClient.invalidateQueries({ queryKey: ["sales", tenant.id] });

  // Vendre ou annuler une vente change le stock, les alertes, le tableau de bord et ce que doivent les clients.
  const invalidateAll = async () => {
    await Promise.all([
      invalidateSales(),
      queryClient.invalidateQueries({ queryKey: ["customers", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["articles", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["warehouses", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["alerts", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["dashboard", tenant.id] }),
    ]);
  };

  // Encaisser ou annuler un paiement ne touche pas au stock.
  const invalidateMoney = async () => {
    await Promise.all([
      invalidateSales(),
      queryClient.invalidateQueries({ queryKey: ["customers", tenant.id] }),
      queryClient.invalidateQueries({ queryKey: ["dashboard", tenant.id] }),
    ]);
  };

  return {
    create: useMutation({
      mutationFn: (input: SaleInput) => createSale(input),
      onSuccess: invalidateAll,
    }),
    cancel: useMutation({
      mutationFn: ({ id, reason }: { id: string; reason: string }) => cancelSale(id, reason),
      onSuccess: invalidateAll,
    }),
    collect: useMutation({
      mutationFn: ({ id, input }: { id: string; input: CollectInput }) => collectPayment(id, input),
      onSuccess: invalidateMoney,
    }),
    voidPayment: useMutation({
      mutationFn: ({ saleId, paymentId, reason }: { saleId: string; paymentId: string; reason: string }) =>
        voidPayment(saleId, paymentId, reason),
      onSuccess: invalidateMoney,
    }),
    collectForCustomer: useMutation({
      mutationFn: ({ customerId, input }: { customerId: string; input: CollectInput }) =>
        collectForCustomer(customerId, input),
      onSuccess: invalidateMoney,
    }),
  };
}