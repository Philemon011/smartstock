"use client";

import { MoreHorizontal, Pencil, Power, Star, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { Warehouse } from "@/lib/api/warehouses";

export type WarehouseHandlers = {
  onEdit: (warehouse: Warehouse) => void;
  onSetMain: (warehouse: Warehouse) => void;
  onActivate: (warehouse: Warehouse) => void;
  onDeactivate: (warehouse: Warehouse) => void;
  onDelete: (warehouse: Warehouse) => void;
};

type WarehouseActionsProps = {
  warehouse: Warehouse;
  busy: boolean;
  handlers: WarehouseHandlers;
};

export function WarehouseActions({ warehouse, busy, handlers }: WarehouseActionsProps) {
  const { can } = warehouse;

  const canEdit = can.update;
  const canSetMain = can.set_main && warehouse.is_active && !warehouse.is_main;
  const canDeactivate = can.toggle && warehouse.is_active && !warehouse.is_main;
  const canActivate = can.toggle && !warehouse.is_active;
  const canDelete = can.delete && !warehouse.is_main;

  if (!canEdit && !canSetMain && !canDeactivate && !canActivate && !canDelete) {
    return null;
  }

  const hasMainActions = canEdit || canSetMain || canDeactivate || canActivate;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className="size-9 px-0"
          aria-label={`Actions pour ${warehouse.name}`}
          disabled={busy}
        >
          <MoreHorizontal strokeWidth={1.5} />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent>
        {canEdit ? (
          <DropdownMenuItem icon={Pencil} onSelect={() => handlers.onEdit(warehouse)}>
            Modifier
          </DropdownMenuItem>
        ) : null}

        {canSetMain ? (
          <DropdownMenuItem icon={Star} onSelect={() => handlers.onSetMain(warehouse)}>
            Définir comme principal
          </DropdownMenuItem>
        ) : null}

        {canDeactivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onDeactivate(warehouse)}>
            Désactiver
          </DropdownMenuItem>
        ) : null}

        {canActivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onActivate(warehouse)}>
            Réactiver
          </DropdownMenuItem>
        ) : null}

        {hasMainActions && canDelete ? <DropdownMenuSeparator /> : null}

        {canDelete ? (
          <DropdownMenuItem icon={Trash2} destructive onSelect={() => handlers.onDelete(warehouse)}>
            Supprimer
          </DropdownMenuItem>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}