"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { UserMenu } from "@/components/app/user-menu";
import { BrandLogo } from "@/components/brand/brand-logo";
import { useSession } from "@/components/session/session-provider";
import { ThemeToggle } from "@/components/theme-toggle";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";

export function AppShell({ children }: { children: ReactNode }) {
  const { signOutError, dismissSignOutError } = useSession();

  return (
    <div className="min-h-dvh bg-background">
      <header className="sticky top-0 z-40 border-b bg-card">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
          <Link href="/" aria-label="Accueil" className="rounded-md">
            <BrandLogo hideNameOnMobile />
          </Link>

          <div className="flex items-center gap-3">
            <ThemeToggle />
            <UserMenu />
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10">
        {signOutError ? (
          <Alert variant="danger" title="Déconnexion impossible" className="mb-6">
            <p>{signOutError}</p>
            <Button variant="ghost" size="sm" className="-ml-3 mt-1" onClick={dismissSignOutError}>
              Fermer
            </Button>
          </Alert>
        ) : null}

        {children}
      </main>
    </div>
  );
}