import type { Metadata } from "next";
import { EntryEdit } from "@/components/stock-entries/entry-edit";

export const metadata: Metadata = {
  title: "Modifier le brouillon",
};

export default async function EditStockEntryPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <EntryEdit id={id} />;
}