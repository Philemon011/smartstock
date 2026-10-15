"use client";

import * as DropdownMenuPrimitive from "@radix-ui/react-dropdown-menu";
import type { LucideIcon } from "lucide-react";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
import { pickerContentStyles } from "./control-styles";

export const DropdownMenu = DropdownMenuPrimitive.Root;
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

export function DropdownMenuItem({
  className,
  icon: Icon,
  destructive = false,
  children,
  ...props
}: DropdownMenuItemProps) {
  return (
    <DropdownMenuPrimitive.Item
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