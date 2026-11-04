"use client";

import { MoreHorizontal, Pencil, Power, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { Supplier } from "@/lib/api/suppliers";

export type SupplierHandlers = {
  onEdit: (supplier: Supplier) => void;
  onActivate: (supplier: Supplier) => void;
  onDeactivate: (supplier: Supplier) => void;
  onDelete: (supplier: Supplier) => void;
};

type SupplierActionsProps = {
  supplier: Supplier;
  busy: boolean;
  handlers: SupplierHandlers;
};

export function SupplierActions({ supplier, busy, handlers }: SupplierActionsProps) {
  const { can } = supplier;

  const canDeactivate = can.toggle && supplier.is_active;
  const canActivate = can.toggle && !supplier.is_active;

  if (!can.update && !canDeactivate && !canActivate && !can.delete) {
    return null;
  }

  const hasMainActions = can.update || canDeactivate || canActivate;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className="size-9 px-0"
                    aria-label={`Actions pour ${supplier.name}`}
          aria-busy={busy || undefined}
        >
          <MoreHorizontal strokeWidth={1.5} />
        </Button>
      </DropdownMenuTrigger>

        <DropdownMenuContent className={busy ? "pointer-events-none opacity-60" : undefined}>
        {can.update ? (
          <DropdownMenuItem icon={Pencil} onSelect={() => handlers.onEdit(supplier)}>
            Modifier
          </DropdownMenuItem>
        ) : null}

        {canDeactivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onDeactivate(supplier)}>
            Désactiver
          </DropdownMenuItem>
        ) : null}

        {canActivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onActivate(supplier)}>
            Réactiver
          </DropdownMenuItem>
        ) : null}

        {hasMainActions && can.delete ? <DropdownMenuSeparator /> : null}

        {can.delete ? (
          <DropdownMenuItem icon={Trash2} destructive onSelect={() => handlers.onDelete(supplier)}>
            Supprimer
          </DropdownMenuItem>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}