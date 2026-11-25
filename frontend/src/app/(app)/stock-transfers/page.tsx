import type { Metadata } from "next";
import { TransfersView } from "@/components/stock-transfers/transfers-view";

export const metadata: Metadata = {
  title: "Transferts de stock",
};

export default function StockTransfersPage() {
  return <TransfersView />;
}