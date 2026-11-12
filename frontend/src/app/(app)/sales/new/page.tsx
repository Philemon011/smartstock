import type { Metadata } from "next";
import { PageHeader } from "@/components/app/page-header";
import { SaleRegister } from "@/components/sales/sale-register";

export const metadata: Metadata = {
  title: "Caisse",
};

export default function NewSalePage() {
  return (
    <div className="max-w-6xl space-y-8">
      <PageHeader
        title="Caisse"
        description="Enregistrez une vente : le stock, les paiements et ce que doit le client se mettent à jour."
      />
      <SaleRegister />
    </div>
  );
}