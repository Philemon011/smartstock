import type { Metadata } from "next";
import { EntryDetail } from "@/components/stock-entries/entry-detail";
import { firstParam } from "@/lib/navigation";

export const metadata: Metadata = {
  title: "Entrée de stock",
};

export default async function StockEntryPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ validate?: string | string[] }>;
}) {
  const { id } = await params;
  const { validate } = await searchParams;

  return <EntryDetail id={id} openValidation={firstParam(validate) === "1"} />;
}