import type { Metadata } from "next";
import { ArticleDetail } from "@/components/articles/article-detail";

export const metadata: Metadata = {
  title: "Fiche article",
};

export default async function ArticlePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <ArticleDetail id={id} />;
}