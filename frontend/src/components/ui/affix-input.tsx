"use client";

import { cn } from "@/lib/utils";
import { Input, type InputProps } from "./input";

export function AffixInput({ suffix, className, ...props }: InputProps & { suffix: string }) {
  return (
    <div className="relative">
      <Input {...props} className={cn("pr-20", className)} />
      <span
        aria-hidden
        className="pointer-events-none absolute right-3.5 top-1/2 max-w-16 -translate-y-1/2 truncate text-sm text-muted-foreground"
      >
        {suffix}
      </span>
    </div>
  );
}