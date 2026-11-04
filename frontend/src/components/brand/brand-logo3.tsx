import { Boxes } from "lucide-react";
import { APP_NAME } from "@/lib/brand";
import { cn } from "@/lib/utils";

type BrandLogoProps = {
  className?: string;
  hideNameOnMobile?: boolean;
};

export function BrandLogo({ className, hideNameOnMobile = false }: BrandLogoProps) {
  return (
    <div className={cn("flex items-center gap-2.5", className)}>
      <span className="flex size-9 items-center justify-center rounded-md bg-primary text-primary-foreground">
        <Boxes className="size-5" strokeWidth={1.5} aria-hidden />
      </span>
      <span
        className={cn(
          "font-display text-lg font-semibold tracking-heading",
          hideNameOnMobile && "hidden sm:inline",
        )}
      >
        {APP_NAME}
      </span>
    </div>
  );
}