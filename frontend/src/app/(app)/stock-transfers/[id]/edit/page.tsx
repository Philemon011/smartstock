import type { Metadata } from "next";
import { TransferEdit } from "@/components/stock-transfers/transfer-edit";

export const metadata: Metadata = {
  title: "Modifier le transfert",
};

export default async function EditStockTransferPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <TransferEdit id={id} />;
}