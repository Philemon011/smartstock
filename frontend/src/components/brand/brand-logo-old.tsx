import { Boxes } from "lucide-react";
import { APP_NAME } from "@/lib/brand";
import { cn } from "@/lib/utils";

export function BrandLogo({ className }: { className?: string }) {
  return (
    <div className={cn("flex items-center gap-2.5", className)}>
      <span className="flex size-9 items-center justify-center rounded-md bg-primary text-primary-foreground">
        <Boxes className="size-5" strokeWidth={1.5} aria-hidden />
      </span>
      <span className="font-display text-lg font-semibold tracking-heading">
        {APP_NAME}
      </span>
    </div>
  );
}