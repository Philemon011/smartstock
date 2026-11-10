"use client";

import { useQuery } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import { getDashboard } from "@/lib/api/dashboard";

export function useDashboard() {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["dashboard", tenant.id],
    queryFn: ({ signal }) => getDashboard(signal),
    refetchInterval: 120_000,
  });
}