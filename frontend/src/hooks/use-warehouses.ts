"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import {
  activateWarehouse,
  createWarehouse,
  deactivateWarehouse,
  deleteWarehouse,
  listWarehouses,
  setMainWarehouse,
  updateWarehouse,
  type WarehouseFilters,
  type WarehouseInput,
} from "@/lib/api/warehouses";

export function useWarehouses(filters: WarehouseFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["warehouses", tenant.id, filters],
    queryFn: ({ signal }) => listWarehouses(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useWarehouseMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["warehouses", tenant.id] });

  return {
    create: useMutation({
      mutationFn: (input: WarehouseInput) => createWarehouse(input),
      onSuccess: invalidate,
    }),
    update: useMutation({
      mutationFn: ({ id, input }: { id: string; input: WarehouseInput }) =>
        updateWarehouse(id, input),
      onSuccess: invalidate,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteWarehouse(id),
      onSuccess: invalidate,
    }),
    setMain: useMutation({
      mutationFn: (id: string) => setMainWarehouse(id),
      onSuccess: invalidate,
    }),
    activate: useMutation({
      mutationFn: (id: string) => activateWarehouse(id),
      onSuccess: invalidate,
    }),
    deactivate: useMutation({
      mutationFn: (id: string) => deactivateWarehouse(id),
      onSuccess: invalidate,
    }),
  };
}