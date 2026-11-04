import type { Metadata } from "next";
import { ArticleEdit } from "@/components/articles/article-edit";

export const metadata: Metadata = {
  title: "Modifier l'article",
};

export default async function EditArticlePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <ArticleEdit id={id} />;
}