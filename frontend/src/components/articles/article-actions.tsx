"use client";

import { Eye, MoreHorizontal, Pencil, Power, Trash2 } from "lucide-react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { Article } from "@/lib/api/articles";

export type ArticleHandlers = {
  onActivate: (article: Article) => void;
  onDeactivate: (article: Article) => void;
  onDelete: (article: Article) => void;
};

type ArticleActionsProps = {
  article: Article;
  busy: boolean;
  handlers: ArticleHandlers;
};

export function ArticleActions({ article, busy, handlers }: ArticleActionsProps) {
  const router = useRouter();
  const { can } = article;

  const canDeactivate = can.toggle && article.is_active;
  const canActivate = can.toggle && !article.is_active;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className="size-9 px-0"
          aria-label={`Actions pour ${article.name}`}
          aria-busy={busy || undefined}
        >
          <MoreHorizontal strokeWidth={1.5} />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent className={busy ? "pointer-events-none opacity-60" : undefined}>
        <DropdownMenuItem icon={Eye} onSelect={() => router.push(`/articles/${article.id}`)}>
          Voir la fiche
        </DropdownMenuItem>

        {can.update ? (
          <DropdownMenuItem icon={Pencil} onSelect={() => router.push(`/articles/${article.id}/edit`)}>
            Modifier
          </DropdownMenuItem>
        ) : null}

        {canDeactivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onDeactivate(article)}>
            Désactiver
          </DropdownMenuItem>
        ) : null}

        {canActivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onActivate(article)}>
            Réactiver
          </DropdownMenuItem>
        ) : null}

        {can.delete ? <DropdownMenuSeparator /> : null}

        {can.delete ? (
          <DropdownMenuItem icon={Trash2} destructive onSelect={() => handlers.onDelete(article)}>
            Supprimer
          </DropdownMenuItem>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}