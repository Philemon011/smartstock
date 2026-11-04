"use client";

import * as DropdownMenu from "@radix-ui/react-dropdown-menu";
import { LogOut } from "lucide-react";
import { useSession } from "@/components/session/session-provider";
import { pickerContentStyles } from "@/components/ui/control-styles";
import { initials } from "@/lib/session";
import { cn } from "@/lib/utils";

export function UserMenu() {
  const { session, signOut } = useSession();

  if (session.status !== "authenticated") {
    return null;
  }

  const { user } = session.me;

  return (
      <DropdownMenu.Root modal={false}>
      <DropdownMenu.Trigger asChild>
        <button
          type="button"
          aria-label="Menu du compte"
          className="flex size-10 items-center justify-center rounded-full bg-accent text-sm font-medium text-foreground outline-none transition-[background-color,box-shadow] duration-200 ease-smooth hover:bg-accent/70 focus-visible:ring-4 focus-visible:ring-ring/15 data-[state=open]:ring-4 data-[state=open]:ring-ring/15"
        >
          {initials(user.name)}
        </button>
      </DropdownMenu.Trigger>

      <DropdownMenu.Portal>
        <DropdownMenu.Content
          align="end"
          sideOffset={8}
          className={cn(
            pickerContentStyles,
            "w-64 origin-[var(--radix-dropdown-menu-content-transform-origin)] p-1",
          )}
        >
          <div className="px-3 py-2.5">
            <p className="truncate text-sm font-medium text-foreground">{user.name}</p>
            <p className="truncate text-xs text-muted-foreground">{user.email}</p>
          </div>

          <DropdownMenu.Separator className="my-1 h-px bg-border" />

          <DropdownMenu.Item
            onSelect={() => void signOut()}
            className="flex h-9 cursor-pointer select-none items-center gap-2.5 rounded-sm px-3 text-sm text-foreground outline-none transition-colors duration-150 data-[highlighted]:bg-accent"
          >
            <LogOut className="size-4 text-muted-foreground" strokeWidth={1.5} aria-hidden />
            Se déconnecter
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  );
}