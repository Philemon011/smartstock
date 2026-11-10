import type { Metadata } from "next";
import { isAlertType } from "@/components/alerts/alert-meta";
import { AlertsView } from "@/components/alerts/alerts-view";
import { firstParam } from "@/lib/navigation";

export const metadata: Metadata = {
  title: "Alertes",
};

export default async function AlertsPage({
  searchParams,
}: {
  searchParams: Promise<{ type?: string | string[] }>;
}) {
  const type = firstParam((await searchParams).type);

  return <AlertsView initialType={isAlertType(type) ? type : "expired"} />;
}