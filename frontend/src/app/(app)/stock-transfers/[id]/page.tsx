import type { Metadata } from "next";
import { TransferDetail } from "@/components/stock-transfers/transfer-detail";
import { firstParam } from "@/lib/navigation";

export const metadata: Metadata = {
  title: "Transfert de stock",
};

export default async function StockTransferPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ validate?: string | string[] }>;
}) {
  const { id } = await params;
  const { validate } = await searchParams;

  return <TransferDetail id={id} openValidation={firstParam(validate) === "1"} />;
}