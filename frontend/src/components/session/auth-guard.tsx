"use client";

import { Loader2 } from "lucide-react";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { currentMembership } from "@/lib/session";
import { useSession } from "./session-provider";

function FullPageLoader() {
  return (
    <div role="status" className="flex min-h-dvh items-center justify-center bg-background">
      <Loader2 className="size-6 animate-spin text-muted-foreground" strokeWidth={1.5} aria-hidden />
      <span className="sr-only">Chargement de votre espace…</span>
    </div>
  );
}

function NoAccess() {
  const { signOut } = useSession();

  return (
    <div className="flex min-h-dvh items-center justify-center bg-background p-6">
      <div className="w-full max-w-md space-y-4">
        <Alert variant="warning" title="Aucun accès actif">
          Votre accès à une entreprise est suspendu ou n&apos;existe plus. Contactez le
          propriétaire de l&apos;entreprise.
        </Alert>
        <Button variant="secondary" size="lg" className="w-full" onClick={() => void signOut()}>
          Se déconnecter
        </Button>
      </div>
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
    return currentMembership(session.me) ? <>{children}</> : <NoAccess />;
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