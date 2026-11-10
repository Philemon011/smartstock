import type { Metadata } from "next";
import { ExitsView } from "@/components/stock-exits/exits-view";

export const metadata: Metadata = {
  title: "Sorties de stock",
};

export default function StockExitsPage() {
  return <ExitsView />;
}