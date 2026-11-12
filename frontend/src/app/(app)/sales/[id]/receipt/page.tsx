import type { Metadata } from "next";
import { SaleReceipt } from "@/components/sales/sale-receipt";

export const metadata: Metadata = {
  title: "Ticket de vente",
};

export default async function SaleReceiptPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <SaleReceipt id={id} />;
}