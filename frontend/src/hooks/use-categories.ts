"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import {
  activateCategory,
  createCategory,
  deactivateCategory,
  deleteCategory,
  listCategories,
  updateCategory,
  type CategoryFilters,
  type CategoryInput,
} from "@/lib/api/categories";

export function useCategories(filters: CategoryFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["categories", tenant.id, filters],
    queryFn: ({ signal }) => listCategories(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useCategoryMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["categories", tenant.id] });

  return {
    create: useMutation({
      mutationFn: (input: CategoryInput) => createCategory(input),
      onSuccess: invalidate,
    }),
    update: useMutation({
      mutationFn: ({ id, input }: { id: string; input: CategoryInput }) => updateCategory(id, input),
      onSuccess: invalidate,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteCategory(id),
      onSuccess: invalidate,
    }),
    activate: useMutation({
      mutationFn: (id: string) => activateCategory(id),
      onSuccess: invalidate,
    }),
    deactivate: useMutation({
      mutationFn: (id: string) => deactivateCategory(id),
      onSuccess: invalidate,
    }),
  };
}