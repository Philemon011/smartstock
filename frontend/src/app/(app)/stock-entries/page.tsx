import type { Metadata } from "next";
import { EntriesView } from "@/components/stock-entries/entries-view";

export const metadata: Metadata = {
  title: "Entrées de stock",
};

export default function StockEntriesPage() {
  return <EntriesView />;
}