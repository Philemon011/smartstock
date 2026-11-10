"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import { getAlertSummary, listAlerts, type AlertType } from "@/lib/api/alerts";

/** Le total des alertes se rafraîchit seul toutes les minutes : la pastille du menu reste juste. */
export function useAlertSummary() {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["alerts", tenant.id, "summary"],
    queryFn: ({ signal }) => getAlertSummary(signal),
    refetchInterval: 60_000,
  });
}

export function useAlerts(type: AlertType, page: number) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["alerts", tenant.id, "list", type, page],
    queryFn: ({ signal }) => listAlerts(type, page, signal),
    placeholderData: keepPreviousData,
  });
}