"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import {
  activateUnit,
  createUnit,
  deactivateUnit,
  deleteUnit,
  listUnits,
  updateUnit,
  type UnitFilters,
  type UnitInput,
} from "@/lib/api/units";

export function useUnits(filters: UnitFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["units", tenant.id, filters],
    queryFn: ({ signal }) => listUnits(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useUnitMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["units", tenant.id] });

  return {
    create: useMutation({
      mutationFn: (input: UnitInput) => createUnit(input),
      onSuccess: invalidate,
    }),
    update: useMutation({
      mutationFn: ({ id, input }: { id: string; input: UnitInput }) => updateUnit(id, input),
      onSuccess: invalidate,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteUnit(id),
      onSuccess: invalidate,
    }),
    activate: useMutation({
      mutationFn: (id: string) => activateUnit(id),
      onSuccess: invalidate,
    }),
    deactivate: useMutation({
      mutationFn: (id: string) => deactivateUnit(id),
      onSuccess: invalidate,
    }),
  };
}