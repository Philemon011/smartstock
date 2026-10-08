import { cva, type VariantProps } from "class-variance-authority";
import { CircleAlert, CircleCheck, Info, TriangleAlert } from "lucide-react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

const alertVariants = cva("flex gap-3 rounded-lg border p-4 text-sm", {
  variants: {
    variant: {
      info: "border-border bg-accent/60 [&>svg]:text-primary",
      success: "border-success/20 bg-success-soft [&>svg]:text-success",
      warning: "border-warning/20 bg-warning-soft [&>svg]:text-warning",
      danger: "border-danger/20 bg-danger-soft [&>svg]:text-danger",
    },
  },
  defaultVariants: {
    variant: "info",
  },
});

const icons = {
  info: Info,
  success: CircleCheck,
  warning: TriangleAlert,
  danger: CircleAlert,
} as const;

type AlertProps = VariantProps<typeof alertVariants> & {
  title?: string;
  children?: ReactNode;
  className?: string;
};

export function Alert({ variant = "info", title, children, className }: AlertProps) {
  const type = variant ?? "info";
  const Icon = icons[type];
  const urgent = type === "danger" || type === "warning";

  return (
    <div role={urgent ? "alert" : "status"} className={cn(alertVariants({ variant: type }), className)}>
      <Icon className="mt-0.5 size-4 shrink-0" strokeWidth={1.5} aria-hidden />
      <div className="space-y-1">
        {title ? <p className="font-medium text-foreground">{title}</p> : null}
        {children ? <div className="text-muted-foreground">{children}</div> : null}
      </div>
    </div>
  );
}