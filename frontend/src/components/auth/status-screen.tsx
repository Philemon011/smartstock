import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

const tones = {
  info: "bg-accent text-primary",
  success: "bg-success-soft text-success",
  danger: "bg-danger-soft text-danger",
} as const;

type StatusScreenProps = {
  icon: LucideIcon;
  tone?: keyof typeof tones;
  title: string;
  children?: ReactNode;
  actions?: ReactNode;
};

export function StatusScreen({
  icon: Icon,
  tone = "info",
  title,
  children,
  actions,
}: StatusScreenProps) {
  return (
    <div className="space-y-8">
      <div className="space-y-5">
        <span className={cn("flex size-12 items-center justify-center rounded-lg", tones[tone])}>
          <Icon className="size-6" strokeWidth={1.5} aria-hidden />
        </span>

        <div className="space-y-3">
          <h1 className="text-3xl">{title}</h1>
          {children ? <div className="space-y-3 text-muted-foreground">{children}</div> : null}
        </div>
      </div>

      {actions ? <div className="space-y-3">{actions}</div> : null}
    </div>
  );
}