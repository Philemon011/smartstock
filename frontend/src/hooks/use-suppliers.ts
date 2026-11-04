"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import {
  activateSupplier,
  createSupplier,
  deactivateSupplier,
  deleteSupplier,
  listSuppliers,
  updateSupplier,
  type SupplierFilters,
  type SupplierInput,
} from "@/lib/api/suppliers";

export function useSuppliers(filters: SupplierFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["suppliers", tenant.id, filters],
    queryFn: ({ signal }) => listSuppliers(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useSupplierMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["suppliers", tenant.id] });

  return {
    create: useMutation({
      mutationFn: (input: SupplierInput) => createSupplier(input),
      onSuccess: invalidate,
    }),
    update: useMutation({
      mutationFn: ({ id, input }: { id: string; input: SupplierInput }) => updateSupplier(id, input),
      onSuccess: invalidate,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteSupplier(id),
      onSuccess: invalidate,
    }),
    activate: useMutation({
      mutationFn: (id: string) => activateSupplier(id),
      onSuccess: invalidate,
    }),
    deactivate: useMutation({
      mutationFn: (id: string) => deactivateSupplier(id),
      onSuccess: invalidate,
    }),
  };
}