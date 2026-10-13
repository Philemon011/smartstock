import type { ReactNode } from "react";
import { AppShell } from "@/components/app/app-shell";
import { AuthGuard } from "@/components/session/auth-guard";
import { SessionProvider } from "@/components/session/session-provider";

export default function AppLayout({ children }: { children: ReactNode }) {
  return (
    <SessionProvider>
      <AuthGuard>
        <AppShell>{children}</AppShell>
      </AuthGuard>
    </SessionProvider>
  );
}