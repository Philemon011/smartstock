"use client";

import { FolderPlus, MoreHorizontal, Pencil, Power, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { Category } from "@/lib/api/categories";

export type CategoryHandlers = {
  onEdit: (category: Category) => void;
  onAddChild: (category: Category) => void;
  onActivate: (category: Category) => void;
  onDeactivate: (category: Category) => void;
  onDelete: (category: Category) => void;
};

type CategoryActionsProps = {
  category: Category;
  busy: boolean;
  handlers: CategoryHandlers;
};

export function CategoryActions({ category, busy, handlers }: CategoryActionsProps) {
  const { can } = category;

  const canDeactivate = can.toggle && category.is_active;
  const canActivate = can.toggle && !category.is_active;

  if (!can.update && !can.add_child && !canDeactivate && !canActivate && !can.delete) {
    return null;
  }

  const hasMainActions = can.update || can.add_child || canDeactivate || canActivate;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className="size-9 px-0"
          aria-label={`Actions pour ${category.name}`}
          aria-busy={busy || undefined}
        >
          <MoreHorizontal strokeWidth={1.5} />
        </Button>
      </DropdownMenuTrigger>

            <DropdownMenuContent className={busy ? "pointer-events-none opacity-60" : undefined}>
        {can.update ? (
          <DropdownMenuItem icon={Pencil} onSelect={() => handlers.onEdit(category)}>
            Modifier
          </DropdownMenuItem>
        ) : null}

        {can.add_child ? (
          <DropdownMenuItem icon={FolderPlus} onSelect={() => handlers.onAddChild(category)}>
            Ajouter une sous-catégorie
          </DropdownMenuItem>
        ) : null}

        {canDeactivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onDeactivate(category)}>
            Désactiver
          </DropdownMenuItem>
        ) : null}

        {canActivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onActivate(category)}>
            Réactiver
          </DropdownMenuItem>
        ) : null}

        {hasMainActions && can.delete ? <DropdownMenuSeparator /> : null}

        {can.delete ? (
          <DropdownMenuItem icon={Trash2} destructive onSelect={() => handlers.onDelete(category)}>
            Supprimer
          </DropdownMenuItem>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}