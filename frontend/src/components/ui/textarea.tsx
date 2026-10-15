"use client";

import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
import { useFieldContext } from "./field";

export function Textarea({
  className,
  id,
  "aria-describedby": describedBy,
  "aria-invalid": invalid,
  ...props
}: ComponentProps<"textarea">) {
  const field = useFieldContext();

  return (
    <textarea
      id={id ?? field?.id}
      aria-describedby={describedBy ?? field?.describedBy}
      aria-invalid={invalid ?? (field?.invalid || undefined)}
      className={cn(
        "min-h-24 w-full resize-y rounded-md border border-input bg-card px-3.5 py-3 text-sm text-foreground outline-none transition-[border-color,box-shadow] duration-200 ease-smooth placeholder:text-subtle-foreground hover:border-muted-foreground/40 focus-visible:border-ring focus-visible:ring-4 focus-visible:ring-ring/15 disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-danger aria-[invalid=true]:focus-visible:ring-danger/15",
        className,
      )}
      {...props}
    />
  );
}