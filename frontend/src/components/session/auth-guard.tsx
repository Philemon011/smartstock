"use client";

import { Loader2 } from "lucide-react";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useSession } from "./session-provider";

function FullPageLoader() {
  return (
    <div role="status" className="flex min-h-dvh items-center justify-center bg-background">
      <Loader2 className="size-6 animate-spin text-muted-foreground" strokeWidth={1.5} aria-hidden />
      <span className="sr-only">Chargement de votre espace…</span>
    </div>
  );
}

export function AuthGuard({ children }: { children: ReactNode }) {
  const { session, reload } = useSession();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (session.status === "signed-out") {
      router.replace("/login");
    }

    if (session.status === "unauthenticated") {
      router.replace(pathname === "/" ? "/login" : `/login?next=${encodeURIComponent(pathname)}`);
    }
  }, [session.status, pathname, router]);

  if (session.status === "authenticated") {
    return <>{children}</>;
  }

  if (session.status === "error") {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-background p-6">
        <div className="w-full max-w-md space-y-4">
          <Alert variant="danger" title="Impossible de charger votre espace">
            {session.message}
          </Alert>
          <Button size="lg" className="w-full" onClick={reload}>
            Réessayer
          </Button>
        </div>
      </div>
    );
  }

  return <FullPageLoader />;
}