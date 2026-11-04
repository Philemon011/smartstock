import type { ReactNode } from "react";
import { AppShell } from "@/components/app/app-shell";
import { AppToaster } from "@/components/providers/app-toaster";
import { PointerEventsGuard } from "@/components/providers/pointer-events-guard";
import { QueryProvider } from "@/components/providers/query-provider";
import { AuthGuard } from "@/components/session/auth-guard";
import { SessionProvider } from "@/components/session/session-provider";

export default function AppLayout({ children }: { children: ReactNode }) {
  return (
    <SessionProvider>
      <AuthGuard>
        <QueryProvider>
          <AppShell>{children}</AppShell>
          <AppToaster />
          <PointerEventsGuard />
        </QueryProvider>
      </AuthGuard>
    </SessionProvider>
  );
}