"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership } from "@/components/session/session-provider";
import {
  activateArticle,
  createArticle,
  deactivateArticle,
  deleteArticle,
  getArticle,
  getPriceHistory,
  listArticles,
  removeArticleImage,
  updateArticle,
  uploadArticleImage,
  type ArticleFilters,
  type ArticleInput,
} from "@/lib/api/articles";

export function useArticles(filters: ArticleFilters) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["articles", tenant.id, "list", filters],
    queryFn: ({ signal }) => listArticles(filters, signal),
    placeholderData: keepPreviousData,
  });
}

export function useArticle(id: string) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["articles", tenant.id, "detail", id],
    queryFn: ({ signal }) => getArticle(id, signal),
  });
}

export function usePriceHistory(id: string) {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["articles", tenant.id, "price-history", id],
    queryFn: ({ signal }) => getPriceHistory(id, signal),
  });
}

export function useArticleMutations() {
  const { tenant } = useActiveMembership();
  const queryClient = useQueryClient();

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["articles", tenant.id] });

  return {
    create: useMutation({
      mutationFn: (input: ArticleInput) => createArticle(input),
      onSuccess: invalidate,
    }),
    update: useMutation({
      mutationFn: ({ id, input }: { id: string; input: ArticleInput }) => updateArticle(id, input),
      onSuccess: invalidate,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteArticle(id),
      onSuccess: invalidate,
    }),
    activate: useMutation({
      mutationFn: (id: string) => activateArticle(id),
      onSuccess: invalidate,
    }),
    deactivate: useMutation({
      mutationFn: (id: string) => deactivateArticle(id),
      onSuccess: invalidate,
    }),
    uploadImage: useMutation({
      mutationFn: ({ id, file }: { id: string; file: File }) => uploadArticleImage(id, file),
      onSuccess: invalidate,
    }),
    removeImage: useMutation({
      mutationFn: (id: string) => removeArticleImage(id),
      onSuccess: invalidate,
    }),
  };
}