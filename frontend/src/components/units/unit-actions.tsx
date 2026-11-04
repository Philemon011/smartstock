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
import type { Unit } from "@/lib/api/units";

export type UnitHandlers = {
  onEdit: (unit: Unit) => void;
  onActivate: (unit: Unit) => void;
  onDeactivate: (unit: Unit) => void;
  onDelete: (unit: Unit) => void;
};

type UnitActionsProps = {
  unit: Unit;
  busy: boolean;
  handlers: UnitHandlers;
};

export function UnitActions({ unit, busy, handlers }: UnitActionsProps) {
  const { can } = unit;

  const canDeactivate = can.toggle && unit.is_active;
  const canActivate = can.toggle && !unit.is_active;

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
          aria-label={`Actions pour ${unit.name}`}
          aria-busy={busy || undefined}
        >
          <MoreHorizontal strokeWidth={1.5} />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent className={busy ? "pointer-events-none opacity-60" : undefined}>
        {can.update ? (
          <DropdownMenuItem icon={Pencil} onSelect={() => handlers.onEdit(unit)}>
            Modifier
          </DropdownMenuItem>
        ) : null}

        {canDeactivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onDeactivate(unit)}>
            Désactiver
          </DropdownMenuItem>
        ) : null}

        {canActivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onActivate(unit)}>
            Réactiver
          </DropdownMenuItem>
        ) : null}

        {hasMainActions && can.delete ? <DropdownMenuSeparator /> : null}

        {can.delete ? (
          <DropdownMenuItem icon={Trash2} destructive onSelect={() => handlers.onDelete(unit)}>
            Supprimer
          </DropdownMenuItem>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}