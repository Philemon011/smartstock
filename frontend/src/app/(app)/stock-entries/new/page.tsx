import type { Metadata } from "next";
import { PageHeader } from "@/components/app/page-header";
import { EntryForm } from "@/components/stock-entries/entry-form";

export const metadata: Metadata = {
  title: "Nouvelle entrée",
};

export default function NewStockEntryPage() {
  return (
    <div className="max-w-4xl space-y-8">
      <PageHeader
        title="Nouvelle entrée"
        description="Enregistrez une livraison. Le stock ne change qu'à la validation."
      />
      <EntryForm entry={null} initialArticles={new Map()} />
    </div>
  );
}