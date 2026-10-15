"use client";

import { Search } from "lucide-react";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
import { Input } from "./input";

export function SearchInput({
  className,
  ...props
}: Omit<ComponentProps<typeof Input>, "type">) {
  return (
    <div className={cn("relative", className)}>
      <Search
        className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        strokeWidth={1.5}
        aria-hidden
      />
      <Input
        type="search"
        {...props}
        className="pl-10 [&::-webkit-search-cancel-button]:hidden"
      />
    </div>
  );
}