import type { Metadata } from "next";
import { WarehousesView } from "@/components/warehouses/warehouses-view";

export const metadata: Metadata = {
  title: "Entrepôts",
};

export default function WarehousesPage() {
  return <WarehousesView />;
}