"use client";

import * as SelectPrimitive from "@radix-ui/react-select";
import { Check, ChevronDown } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  controlHeights,
  pickerContentStyles,
  pickerTriggerStyles,
  type ControlSize,
} from "./control-styles";
import { useFieldContext } from "./field";

export type SelectOption = {
  value: string;
  label: string;
};

type SelectProps = {
  options: SelectOption[];
  value?: string;
  onValueChange?: (value: string) => void;
  placeholder?: string;
  name?: string;
  disabled?: boolean;
  size?: ControlSize;
  className?: string;
};

export function Select({
  options,
  value,
  onValueChange,
  placeholder = "Sélectionner",
  name,
  disabled,
  size = "md",
  className,
}: SelectProps) {
  const field = useFieldContext();

  return (
    <SelectPrimitive.Root
      value={value}
      onValueChange={onValueChange}
      name={name}
      disabled={disabled}
    >
      <SelectPrimitive.Trigger
        id={field?.id}
        aria-invalid={field?.invalid || undefined}
        aria-describedby={field?.describedBy}
        className={cn(pickerTriggerStyles, controlHeights[size], className)}
      >
        <SelectPrimitive.Value placeholder={placeholder} />
        <SelectPrimitive.Icon asChild>
          <ChevronDown
            className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 ease-smooth group-data-[state=open]:rotate-180"
            strokeWidth={1.5}
          />
        </SelectPrimitive.Icon>
      </SelectPrimitive.Trigger>

      <SelectPrimitive.Portal>
        <SelectPrimitive.Content
          position="popper"
          sideOffset={6}
          className={cn(
            pickerContentStyles,
            "max-h-[min(18rem,var(--radix-select-content-available-height))] w-[var(--radix-select-trigger-width)] origin-[var(--radix-select-content-transform-origin)]",
          )}
        >
          <SelectPrimitive.Viewport className="p-1">
            {options.map((option) => (
              <SelectPrimitive.Item
                key={option.value}
                value={option.value}
                className="relative flex h-9 cursor-pointer select-none items-center rounded-sm pl-3 pr-9 text-sm text-foreground outline-none transition-colors duration-150 data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[highlighted]:bg-accent data-[state=checked]:font-medium"
              >
                <SelectPrimitive.ItemText>{option.label}</SelectPrimitive.ItemText>
                <SelectPrimitive.ItemIndicator className="absolute right-3">
                  <Check className="size-4 text-primary" strokeWidth={1.5} />
                </SelectPrimitive.ItemIndicator>
              </SelectPrimitive.Item>
            ))}
          </SelectPrimitive.Viewport>
        </SelectPrimitive.Content>
      </SelectPrimitive.Portal>
    </SelectPrimitive.Root>
  );
}