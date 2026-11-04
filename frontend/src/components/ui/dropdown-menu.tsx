"use client";

import * as DropdownMenuPrimitive from "@radix-ui/react-dropdown-menu";
import type { LucideIcon } from "lucide-react";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
import { pickerContentStyles } from "./control-styles";

/**
 * Menu non modal : un menu modal bloque les clics sur toute la page et entre en conflit
 * avec les panneaux et boîtes de dialogue que ses actions ouvrent.
 */
export function DropdownMenu(props: ComponentProps<typeof DropdownMenuPrimitive.Root>) {
  return <DropdownMenuPrimitive.Root modal={false} {...props} />;
}

export const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;

export function DropdownMenuContent({
  className,
  sideOffset = 8,
  align = "end",
  ...props
}: ComponentProps<typeof DropdownMenuPrimitive.Content>) {
  return (
    <DropdownMenuPrimitive.Portal>
      <DropdownMenuPrimitive.Content
        align={align}
        sideOffset={sideOffset}
        className={cn(
          pickerContentStyles,
          "min-w-56 origin-[var(--radix-dropdown-menu-content-transform-origin)] p-1",
          className,
        )}
        {...props}
      />
    </DropdownMenuPrimitive.Portal>
  );
}

type DropdownMenuItemProps = ComponentProps<typeof DropdownMenuPrimitive.Item> & {
  icon?: LucideIcon;
  destructive?: boolean;
};

/** Délai laissant le menu se fermer complètement avant que l'action n'ouvre autre chose. */
const SELECT_DELAY_MS = 50;

export function DropdownMenuItem({
  className,
  icon: Icon,
  destructive = false,
  children,
  onSelect,
  ...props
}: DropdownMenuItemProps) {
  return (
    <DropdownMenuPrimitive.Item
      onSelect={(event) => {
        window.setTimeout(() => onSelect?.(event), SELECT_DELAY_MS);
      }}
      className={cn(
        "flex h-9 cursor-pointer select-none items-center gap-2.5 rounded-sm px-3 text-sm outline-none transition-colors duration-150 data-[disabled]:pointer-events-none data-[disabled]:opacity-50",
        destructive
          ? "text-danger data-[highlighted]:bg-danger-soft"
          : "text-foreground data-[highlighted]:bg-accent",
        className,
      )}
      {...props}
    >
      {Icon ? (
        <Icon
          className={cn("size-4", destructive ? "text-danger" : "text-muted-foreground")}
          strokeWidth={1.5}
          aria-hidden
        />
      ) : null}
      {children}
    </DropdownMenuPrimitive.Item>
  );
}

export function DropdownMenuSeparator({
  className,
  ...props
}: ComponentProps<typeof DropdownMenuPrimitive.Separator>) {
  return <DropdownMenuPrimitive.Separator className={cn("my-1 h-px bg-border", className)} {...props} />;
}