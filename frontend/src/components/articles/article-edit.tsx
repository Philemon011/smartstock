"use client";

import Link from "next/link";
import { PageHeader } from "@/components/app/page-header";
import { ArticleForm } from "@/components/articles/article-form";
import { Alert } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useArticle } from "@/hooks/use-articles";
import { ApiError } from "@/lib/api/client";

export function ArticleEdit({ id }: { id: string }) {
  const query = useArticle(id);

  if (query.isPending) {
    return (
      <div className="max-w-3xl space-y-6">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-96 w-full rounded-xl" />
      </div>
    );
  }

  if (query.isError) {
    return (
      <div className="max-w-3xl space-y-4">
        <Alert variant="danger" title="Impossible de charger l'article">
          {query.error instanceof ApiError
            ? query.error.message
            : "Une erreur inattendue est survenue. Réessayez."}
        </Alert>
        <div className="flex gap-3">
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
          <Link href="/articles" className={buttonVariants({ variant: "ghost" })}>
            Retour aux articles
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader title="Modifier l'article" description={`${query.data.name} · ${query.data.code}`} />
      <ArticleForm article={query.data} />
    </div>
  );
}