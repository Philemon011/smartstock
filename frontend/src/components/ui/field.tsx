"use client";

import { CircleAlert } from "lucide-react";
import { createContext, useContext, useId, type ReactNode } from "react";
import { cn } from "@/lib/utils";

type FieldContextValue = {
  id: string;
  describedBy: string | undefined;
  invalid: boolean;
};

const FieldContext = createContext<FieldContextValue | null>(null);

export function useFieldContext(): FieldContextValue | null {
  return useContext(FieldContext);
}

type FieldProps = {
  label: string;
  hint?: string;
  error?: string;
  optional?: boolean;
  className?: string;
  children: ReactNode;
};

export function Field({ label, hint, error, optional = false, className, children }: FieldProps) {
  const baseId = useId();
  const inputId = `${baseId}-input`;
  const hintId = `${baseId}-hint`;
  const errorId = `${baseId}-error`;

  const describedBy =
    [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(" ") || undefined;

  return (
    <FieldContext.Provider value={{ id: inputId, describedBy, invalid: Boolean(error) }}>
      <div className={cn("flex flex-col gap-1.5", className)}>
        <label htmlFor={inputId} className="text-sm font-medium text-foreground">
          {label}
          {optional ? (
            <span className="ml-1.5 font-normal text-subtle-foreground">Optionnel</span>
          ) : null}
        </label>

        {children}

        {error ? (
          <p id={errorId} aria-live="polite" className="flex items-start gap-1.5 text-sm text-danger">
            <CircleAlert className="mt-0.5 size-3.5 shrink-0" strokeWidth={1.5} aria-hidden />
            <span>{error}</span>
          </p>
        ) : null}

        {hint ? (
          <p id={hintId} className="text-xs text-muted-foreground">
            {hint}
          </p>
        ) : null}
      </div>
    </FieldContext.Provider>
  );
}