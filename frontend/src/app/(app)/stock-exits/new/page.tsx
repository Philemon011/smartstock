import type { Metadata } from "next";
import { PageHeader } from "@/components/app/page-header";
import { ExitNew } from "@/components/stock-exits/exit-new";
import { firstParam } from "@/lib/navigation";

export const metadata: Metadata = {
  title: "Nouvelle sortie",
};

export default async function NewStockExitPage({
  searchParams,
}: {
  searchParams: Promise<{ article?: string | string[]; lot?: string | string[] }>;
}) {
  const params = await searchParams;

  return (
    <div className="max-w-4xl space-y-8">
      <PageHeader
        title="Nouvelle sortie"
        description="Enregistrez une perte, une casse, un don ou un retour fournisseur. Le stock ne change qu'à la validation."
      />
      <ExitNew articleId={firstParam(params.article) ?? null} lotId={firstParam(params.lot) ?? null} />
    </div>
  );
}