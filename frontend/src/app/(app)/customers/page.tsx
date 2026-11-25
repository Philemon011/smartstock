import type { Metadata } from "next";
import { CustomersView } from "@/components/customers/customers-view";
import { firstParam } from "@/lib/navigation";

export const metadata: Metadata = {
  title: "Clients",
};

export default async function CustomersPage({
  searchParams,
}: {
  searchParams: Promise<{ due?: string | string[] }>;
}) {
  const { due } = await searchParams;

  return <CustomersView initialDue={firstParam(due) === "1"} />;
}