import type { Metadata } from "next";
import { PageHeader } from "@/components/app/page-header";
import { TransferForm } from "@/components/stock-transfers/transfer-form";

export const metadata: Metadata = {
  title: "Nouveau transfert",
};

export default function NewStockTransferPage() {
  return (
    <div className="max-w-4xl space-y-8">
      <PageHeader
        title="Nouveau transfert"
        description="Déplacez du stock d'un entrepôt à l'autre. Le stock ne change qu'à la validation."
      />
      <TransferForm transfer={null} initialArticles={new Map()} />
    </div>
  );
}