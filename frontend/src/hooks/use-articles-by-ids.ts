"use client";

import { useQueries } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import { getArticle, type Article } from "@/lib/api/articles";

/** Charge les fiches complètes (avec conditionnements) des articles d'un brouillon. */
export function useArticlesByIds(ids: string[]) {
  const { tenant } = useActiveMembership();

  const results = useQueries({
    queries: ids.map((id) => ({
      queryKey: ["articles", tenant.id, "detail", id],
      queryFn: ({ signal }: { signal: AbortSignal }) => getArticle(id, signal),
    })),
  });

  const articles = new Map<string, Article>();

  for (const result of results) {
    if (result.data) {
      articles.set(result.data.id, result.data);
    }
  }

  return {
    articles,
    isPending: results.some((result) => result.isPending),
    isError: results.some((result) => result.isError),
  };
}