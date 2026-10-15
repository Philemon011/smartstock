"use client";

import { useTheme } from "next-themes";
import type { CSSProperties } from "react";
import { Toaster } from "sonner";

export function AppToaster() {
  const { resolvedTheme } = useTheme();

  return (
    <Toaster
      position="bottom-right"
      theme={resolvedTheme === "dark" ? "dark" : "light"}
      toastOptions={{ style: { fontFamily: "var(--font-sans)", fontSize: "0.875rem" } }}
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
          "--border-radius": "0.75rem",
        } as CSSProperties
      }
    />
  );
}