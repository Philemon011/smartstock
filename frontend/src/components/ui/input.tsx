"use client";

import { cva, type VariantProps } from "class-variance-authority";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
import { useFieldContext } from "./field";

const inputVariants = cva(
  "w-full rounded-md border border-input bg-card px-3.5 text-sm text-foreground outline-none transition-[border-color,box-shadow] duration-200 ease-smooth placeholder:text-subtle-foreground hover:border-muted-foreground/40 focus-visible:border-ring focus-visible:ring-4 focus-visible:ring-ring/15 disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-danger aria-[invalid=true]:focus-visible:ring-danger/15",
  {
    variants: {
      size: {
        sm: "h-9",
        md: "h-10",
        lg: "h-11",
      },
    },
    defaultVariants: {
      size: "md",
    },
  },
);

export type InputProps = Omit<ComponentProps<"input">, "size"> & VariantProps<typeof inputVariants>;

export function Input({
  className,
  size,
  id,
  "aria-describedby": describedBy,
  "aria-invalid": invalid,
  ...props
}: InputProps) {
  const field = useFieldContext();

  return (
    <input
      id={id ?? field?.id}
      aria-describedby={describedBy ?? field?.describedBy}
      aria-invalid={invalid ?? (field?.invalid || undefined)}
      className={cn(inputVariants({ size }), className)}
      {...props}
    />
  );
}