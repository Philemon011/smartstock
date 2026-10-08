"use client";

import * as PopoverPrimitive from "@radix-ui/react-popover";
import { Command } from "cmdk";
import { Check, ChevronDown, Search } from "lucide-react";
import { useState } from "react";
import { cn } from "@/lib/utils";
import {
  controlHeights,
  pickerContentStyles,
  pickerTriggerStyles,
  type ControlSize,
} from "./control-styles";
import { useFieldContext } from "./field";

export type ComboboxOption = {
  value: string;
  label: string;
};

type ComboboxProps = {
  options: ComboboxOption[];
  value?: string;
  onValueChange?: (value: string) => void;
  placeholder?: string;
  searchPlaceholder?: string;
  emptyMessage?: string;
  name?: string;
  disabled?: boolean;
  size?: ControlSize;
  className?: string;
};

function normalize(text: string): string {
  return text
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim();
}

/**
 * Recherche sans accents ni majuscules.
 * 1 = le libellé (ou le code) commence par la saisie, 0.8 = un mot commence par la saisie,
 * 0.5 = la saisie est contenue quelque part, 0 = aucun résultat.
 */
function filterOption(value: string, search: string, keywords?: string[]): number {
  const query = normalize(search);

  if (query === "") return 1;

  const label = normalize(keywords?.[0] ?? "");
  const code = normalize(value);

  if (label.startsWith(query) || code === query) return 1;
  if (label.split(/[\s'’-]+/).some((word) => word.startsWith(query))) return 0.8;
  if (label.includes(query)) return 0.5;

  return 0;
}

export function Combobox({
  options,
  value,
  onValueChange,
  placeholder = "Sélectionner",
  searchPlaceholder = "Rechercher…",
  emptyMessage = "Aucun résultat pour cette recherche.",
  name,
  disabled,
  size = "md",
  className,
}: ComboboxProps) {
  const field = useFieldContext();
  const [open, setOpen] = useState(false);
  const selected = options.find((option) => option.value === value);

  return (
    <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
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
          data-placeholder={selected ? undefined : ""}
          className={cn(pickerTriggerStyles, controlHeights[size], className)}
        >
          <span className="truncate">{selected ? selected.label : placeholder}</span>
          <ChevronDown
            className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 ease-smooth group-data-[state=open]:rotate-180"
            strokeWidth={1.5}
            aria-hidden
          />
        </button>
      </PopoverPrimitive.Trigger>

      {name ? <input type="hidden" name={name} value={value ?? ""} /> : null}

      <PopoverPrimitive.Portal>
        <PopoverPrimitive.Content
          align="start"
          sideOffset={6}
          className={cn(
            pickerContentStyles,
            "w-[var(--radix-popover-trigger-width)] origin-[var(--radix-popover-content-transform-origin)]",
          )}
        >
          <Command filter={filterOption} loop className="flex flex-col">
            <div className="flex items-center gap-2 border-b px-3.5">
              <Search
                className="size-4 shrink-0 text-muted-foreground"
                strokeWidth={1.5}
                aria-hidden
              />
              <Command.Input
                placeholder={searchPlaceholder}
                className="h-11 w-full bg-transparent text-sm text-foreground outline-none placeholder:text-subtle-foreground"
              />
            </div>

            <Command.List className="max-h-[min(16rem,var(--radix-popover-content-available-height))] overflow-y-auto p-1">
              <Command.Empty className="px-3 py-6 text-center text-sm text-muted-foreground">
                {emptyMessage}
              </Command.Empty>

              {options.map((option) => (
                <Command.Item
                  key={option.value}
                  value={option.value}
                  keywords={[option.label]}
                  onSelect={() => {
                    onValueChange?.(option.value);
                    setOpen(false);
                  }}
                  className="relative flex h-9 cursor-pointer select-none items-center rounded-sm pl-3 pr-9 text-sm text-foreground outline-none transition-colors duration-150 data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-50 data-[selected=true]:bg-accent"
                >
                  <span className={cn("truncate", option.value === value && "font-medium")}>
                    {option.label}
                  </span>
                  {option.value === value ? (
                    <Check
                      className="absolute right-3 size-4 text-primary"
                      strokeWidth={1.5}
                      aria-hidden
                    />
                  ) : null}
                </Command.Item>
              ))}
            </Command.List>
          </Command>
        </PopoverPrimitive.Content>
      </PopoverPrimitive.Portal>
    </PopoverPrimitive.Root>
  );
}