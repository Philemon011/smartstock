
"use client";

import Image from "next/image";
import { useTheme } from "next-themes";
import { useSyncExternalStore } from "react";
import { cn } from "@/lib/utils";

const subscribe = () => () => {};

export function BrandLogo({
  className,
}: {
  className?: string;
}) {
  const { resolvedTheme } = useTheme();

  const mounted = useSyncExternalStore(
    subscribe,
    () => true,
    () => false,
  );

  const isDark = mounted && resolvedTheme === "dark";

  return (
    <div className={cn("flex items-center gap-2.5", className)}>
      <Image
        src={
          isDark
            ? "/images/logo-smart-stock-on-black.png"
            : "/images/logo-smart-stock.png"
        }
        alt="SmartStock"
        width={300}
        height={60}
        priority
className="h-14 w-auto object-contain"

      />
    </div>
  );
}

