"use client";

import { Eye, MoreHorizontal, Pencil, Trash2 } from "lucide-react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { StockEntry } from "@/lib/api/stock-entries";

type EntryActionsProps = {
  entry: StockEntry;
  busy: boolean;
  onDelete: (entry: StockEntry) => void;
};

export function EntryActions({ entry, busy, onDelete }: EntryActionsProps) {
  const router = useRouter();
  const label = entry.number ?? "le brouillon";

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className="size-9 px-0"
          aria-label={`Actions pour ${label}`}
          aria-busy={busy || undefined}
        >
          <MoreHorizontal strokeWidth={1.5} />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent className={busy ? "pointer-events-none opacity-60" : undefined}>
        <DropdownMenuItem icon={Eye} onSelect={() => router.push(`/stock-entries/${entry.id}`)}>
          Voir l&apos;entrée
        </DropdownMenuItem>

        {entry.can.update ? (
          <DropdownMenuItem icon={Pencil} onSelect={() => router.push(`/stock-entries/${entry.id}/edit`)}>
            Modifier le brouillon
          </DropdownMenuItem>
        ) : null}

        {entry.can.delete ? <DropdownMenuSeparator /> : null}

        {entry.can.delete ? (
          <DropdownMenuItem icon={Trash2} destructive onSelect={() => onDelete(entry)}>
            Supprimer le brouillon
          </DropdownMenuItem>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}