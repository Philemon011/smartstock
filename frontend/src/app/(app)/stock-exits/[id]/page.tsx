import type { Metadata } from "next";
import { ExitDetail } from "@/components/stock-exits/exit-detail";
import { firstParam } from "@/lib/navigation";

export const metadata: Metadata = {
  title: "Sortie de stock",
};

export default async function StockExitPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ validate?: string | string[] }>;
}) {
  const { id } = await params;
  const { validate } = await searchParams;

  return <ExitDetail id={id} openValidation={firstParam(validate) === "1"} />;
}