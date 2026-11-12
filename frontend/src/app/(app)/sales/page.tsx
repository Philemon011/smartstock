import type { Metadata } from "next";
import { SalesView } from "@/components/sales/sales-view";

export const metadata: Metadata = {
  title: "Ventes",
};

export default function SalesPage() {
  return <SalesView />;
}