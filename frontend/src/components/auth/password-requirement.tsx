import { Circle, CircleCheck } from "lucide-react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

type PasswordRequirementProps = {
  met: boolean;
  children: ReactNode;
};

export function PasswordRequirement({ met, children }: PasswordRequirementProps) {
  const Icon = met ? CircleCheck : Circle;

  return (
    <p
      className={cn(
        "flex items-center gap-2 text-xs transition-colors duration-200 ease-smooth",
        met ? "text-success" : "text-muted-foreground",
      )}
    >
      <Icon className="size-3.5 shrink-0" strokeWidth={1.5} aria-hidden />
      <span className="sr-only">{met ? "Condition remplie : " : "Condition à remplir : "}</span>
      {children}
    </p>
  );
}