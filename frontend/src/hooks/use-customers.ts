"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import {
  activateCustomer,
  createCustomer,
  deactivateCustomer,
  deleteCustomer,
  listCustomers,
  updateCustomer,
  type CustomerFilters,
  type CustomerInput,
} from "@/lib/api/customers";

export function useCustomers(filters: CustomerFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["customers", tenant.id, filters],
    queryFn: ({ signal }) => listCustomers(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useCustomerMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["customers", tenant.id] });

  return {
    create: useMutation({
      mutationFn: (input: CustomerInput) => createCustomer(input),
      onSuccess: invalidate,
    }),
    update: useMutation({
      mutationFn: ({ id, input }: { id: string; input: CustomerInput }) => updateCustomer(id, input),
      onSuccess: invalidate,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteCustomer(id),
      onSuccess: invalidate,
    }),
    activate: useMutation({
      mutationFn: (id: string) => activateCustomer(id),
      onSuccess: invalidate,
    }),
    deactivate: useMutation({
      mutationFn: (id: string) => deactivateCustomer(id),
      onSuccess: invalidate,
    }),
  };
}