"use client";

import * as PopoverPrimitive from "@radix-ui/react-popover";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Command } from "cmdk";
import { ChevronDown, Loader2, Search, X } from "lucide-react";
import { useState, type ReactNode } from "react";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { cn } from "@/lib/utils";
import {
  controlHeights,
  pickerContentStyles,
  pickerTriggerStyles,
  type ControlSize,
} from "./control-styles";
import { useFieldContext } from "./field";

type RemoteComboboxProps<T> = {
  queryKey: readonly unknown[];
  fetcher: (term: string, signal: AbortSignal) => Promise<T[]>;
  value: T | null;
  getKey: (item: T) => string;
  renderItem: (item: T) => ReactNode;
  onChange: (item: T) => void;
  onClear?: () => void;
  placeholder?: string;
  searchPlaceholder?: string;
  emptyMessage?: string;
  footer?: (close: () => void) => ReactNode;
  size?: ControlSize;
  disabled?: boolean;
  className?: string;
};

export function RemoteCombobox<T>({
  queryKey,
  fetcher,
  value,
  getKey,
  renderItem,
  onChange,
  onClear,
  placeholder = "Sélectionner",
  searchPlaceholder = "Rechercher…",
  emptyMessage = "Aucun résultat pour cette recherche.",
  footer,
  size = "md",
  disabled,
  className,
}: RemoteComboboxProps<T>) {
  const field = useFieldContext();
  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState("");
  const debounced = useDebouncedValue(term, 250).trim();

  const query = useQuery({
    queryKey: [...queryKey, debounced],
    queryFn: ({ signal }) => fetcher(debounced, signal),
    enabled: open,
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });

  const items = query.data ?? [];
  const clearable = Boolean(value && onClear && !disabled);

  return (
    <PopoverPrimitive.Root
      open={open}
      onOpenChange={(next) => {
        setOpen(next);

        if (!next) setTerm("");
      }}
    >
      <div className="relative">
        <PopoverPrimitive.Trigger asChild>
          <button
            type="button"
            id={field?.id}
            role="combobox"
            aria-expanded={open}
            aria-haspopup="listbox"
            aria-invalid={field?.invalid || undefined}
            aria-describedby={field?.describedBy}
            disabled={disabled}
            data-placeholder={value ? undefined : ""}
            className={cn(
              pickerTriggerStyles,
              controlHeights[size],
              clearable && "pr-16",
              className,
            )}
          >
            <span className="min-w-0 flex-1 truncate">{value ? renderItem(value) : placeholder}</span>
            <ChevronDown
              className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 ease-smooth group-data-[state=open]:rotate-180"
              strokeWidth={1.5}
              aria-hidden
            />
          </button>
        </PopoverPrimitive.Trigger>

        {clearable ? (
          <button
            type="button"
            aria-label="Retirer la sélection"
            onClick={onClear}
            className="absolute right-9 top-1/2 flex size-6 -translate-y-1/2 items-center justify-center rounded-sm text-muted-foreground outline-none transition-colors duration-200 ease-smooth hover:bg-accent hover:text-foreground focus-visible:ring-4 focus-visible:ring-ring/15"
          >
            <X className="size-3.5" strokeWidth={1.5} aria-hidden />
          </button>
        ) : null}
      </div>

      <PopoverPrimitive.Portal>
        <PopoverPrimitive.Content
          align="start"
          sideOffset={6}
          className={cn(
            pickerContentStyles,
            "w-[var(--radix-popover-trigger-width)] min-w-64 origin-[var(--radix-popover-content-transform-origin)]",
          )}
        >
          <Command shouldFilter={false} loop className="flex flex-col">
            <div className="flex items-center gap-2 border-b px-3.5">
              <Search className="size-4 shrink-0 text-muted-foreground" strokeWidth={1.5} aria-hidden />
              <Command.Input
                value={term}
                onValueChange={setTerm}
                placeholder={searchPlaceholder}
                className="h-11 w-full bg-transparent text-sm text-foreground outline-none placeholder:text-subtle-foreground"
              />
              {query.isFetching ? (
                <Loader2
                  className="size-4 shrink-0 animate-spin text-muted-foreground"
                  strokeWidth={1.5}
                  aria-hidden
                />
              ) : null}
            </div>

            <Command.List className="max-h-[min(18rem,var(--radix-popover-content-available-height))] overflow-y-auto p-1">
              <Command.Empty className="px-3 py-6 text-center text-sm text-muted-foreground">
                {query.isError
                  ? "Impossible de charger la liste. Réessayez."
                  : query.isFetching
                    ? "Recherche…"
                    : emptyMessage}
              </Command.Empty>

              {items.map((item) => (
                <Command.Item
                  key={getKey(item)}
                  value={getKey(item)}
                  onSelect={() => {
                    onChange(item);
                    setOpen(false);
                  }}
                  className="flex min-h-11 cursor-pointer select-none items-center rounded-sm px-3 py-1.5 text-sm text-foreground outline-none transition-colors duration-150 data-[selected=true]:bg-accent"
                >
                  {renderItem(item)}
                </Command.Item>
              ))}
            </Command.List>
          </Command>

          {footer ? <div className="border-t p-1">{footer(() => setOpen(false))}</div> : null}
        </PopoverPrimitive.Content>
      </PopoverPrimitive.Portal>
    </PopoverPrimitive.Root>
  );
}