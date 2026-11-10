import type { Metadata } from "next";
import { ExitEdit } from "@/components/stock-exits/exit-edit";

export const metadata: Metadata = {
  title: "Modifier la sortie",
};

export default async function EditStockExitPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <ExitEdit id={id} />;
}