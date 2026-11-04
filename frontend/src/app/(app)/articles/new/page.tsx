import type { Metadata } from "next";
import { PageHeader } from "@/components/app/page-header";
import { ArticleForm } from "@/components/articles/article-form";

export const metadata: Metadata = {
  title: "Nouvel article",
};

export default function NewArticlePage() {
  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader
        title="Nouvel article"
        description="Son code interne (ART-…) sera créé automatiquement."
      />
      <ArticleForm article={null} />
    </div>
  );
}