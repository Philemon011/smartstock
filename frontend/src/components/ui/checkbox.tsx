import { Check } from "lucide-react";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";

type CheckboxProps = Omit<ComponentProps<"input">, "type"> & {
  label: string;
};

export function Checkbox({ label, className, ...props }: CheckboxProps) {
  return (
    <label
      className={cn(
        "flex w-fit cursor-pointer select-none items-center gap-2.5 text-sm text-foreground",
        className,
      )}
    >
      <span className="relative flex size-5 shrink-0">
        <input
          type="checkbox"
          className="peer size-5 cursor-pointer appearance-none rounded-sm border border-input bg-card outline-none transition-colors duration-200 ease-smooth hover:border-muted-foreground/40 checked:border-primary checked:bg-primary focus-visible:ring-4 focus-visible:ring-ring/15 disabled:cursor-not-allowed disabled:opacity-50"
          {...props}
        />
        <Check
          className="pointer-events-none absolute inset-0 m-auto size-3.5 text-primary-foreground opacity-0 transition-opacity duration-200 ease-smooth peer-checked:opacity-100"
          strokeWidth={2.5}
          aria-hidden
        />
      </span>
      {label}
    </label>
  );
}