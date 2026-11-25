"use client";

import { Banknote, MoreHorizontal, Pencil, Power, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { Customer } from "@/lib/api/customers";

export type CustomerHandlers = {
  onEdit: (customer: Customer) => void;
  onActivate: (customer: Customer) => void;
  onDeactivate: (customer: Customer) => void;
  onDelete: (customer: Customer) => void;
  onCollect: (customer: Customer) => void;
};

/** Ce client a un reste à payer. */
export function owes(customer: Customer): boolean {
  return /[1-9]/.test(customer.balance_due ?? "0");
}

/**
 * Encaisser demande le même droit que modifier un client (vendeur, administrateur, propriétaire)
 * sur une entreprise qui accepte les écritures : le serveur en fait foi.
 */
export function canCollect(customer: Customer): boolean {
  return customer.can.update && owes(customer);
}

type CustomerActionsProps = {
  customer: Customer;
  busy: boolean;
  handlers: CustomerHandlers;
};

export function CustomerActions({ customer, busy, handlers }: CustomerActionsProps) {
  const { can } = customer;

  const collect = canCollect(customer);
  const canDeactivate = can.toggle && customer.is_active;
  const canActivate = can.toggle && !customer.is_active;

  if (!collect && !can.update && !canDeactivate && !canActivate && !can.delete) {
    return null;
  }

  const hasMainActions = collect || can.update || canDeactivate || canActivate;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className="size-9 px-0"
          aria-label={`Actions pour ${customer.name}`}
          aria-busy={busy || undefined}
        >
          <MoreHorizontal strokeWidth={1.5} />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent className={busy ? "pointer-events-none opacity-60" : undefined}>
        {collect ? (
          <DropdownMenuItem icon={Banknote} onSelect={() => handlers.onCollect(customer)}>
            Encaisser un paiement
          </DropdownMenuItem>
        ) : null}

        {can.update ? (
          <DropdownMenuItem icon={Pencil} onSelect={() => handlers.onEdit(customer)}>
            Modifier
          </DropdownMenuItem>
        ) : null}

        {canDeactivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onDeactivate(customer)}>
            Désactiver
          </DropdownMenuItem>
        ) : null}

        {canActivate ? (
          <DropdownMenuItem icon={Power} onSelect={() => handlers.onActivate(customer)}>
            Réactiver
          </DropdownMenuItem>
        ) : null}

        {hasMainActions && can.delete ? <DropdownMenuSeparator /> : null}

        {can.delete ? (
          <DropdownMenuItem icon={Trash2} destructive onSelect={() => handlers.onDelete(customer)}>
            Supprimer
          </DropdownMenuItem>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}