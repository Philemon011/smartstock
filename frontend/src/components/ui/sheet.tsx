"use client";

import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export const Sheet = DialogPrimitive.Root;

type SheetContentProps = {
  side?: "left" | "right";
  title: string;
  description?: string;
  children: ReactNode;
  className?: string;
};

const sides = {
  right:
    "right-0 w-full max-w-md border-l data-[state=open]:animate-slide-in-right data-[state=closed]:animate-slide-out-right",
  left: "left-0 w-80 max-w-[85vw] border-r data-[state=open]:animate-slide-in-left data-[state=closed]:animate-slide-out-left",
} as const;

export function SheetContent({
  side = "right",
  title,
  description,
  children,
  className,
}: SheetContentProps) {
  return (
    <DialogPrimitive.Portal>
      <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-foreground/30 data-[state=closed]:animate-fade-out data-[state=open]:animate-fade-in" />

      <DialogPrimitive.Content
        className={cn(
          "fixed inset-y-0 z-50 flex flex-col bg-card shadow-popover outline-none",
          sides[side],
          className,
        )}
      >
        <div className="border-b px-6 py-5 pr-16">
          <DialogPrimitive.Title className="font-display text-lg font-semibold tracking-heading">
            {title}
          </DialogPrimitive.Title>
          <DialogPrimitive.Description
            className={description ? "mt-1 text-sm text-muted-foreground" : "sr-only"}
          >
            {description ?? title}
          </DialogPrimitive.Description>
        </div>

        {children}

        <DialogPrimitive.Close
          aria-label="Fermer"
          className="absolute right-4 top-4 flex size-9 items-center justify-center rounded-md text-muted-foreground outline-none transition-colors duration-200 ease-smooth hover:bg-accent hover:text-foreground focus-visible:ring-4 focus-visible:ring-ring/15"
        >
          <X className="size-4" strokeWidth={1.5} aria-hidden />
        </DialogPrimitive.Close>
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}