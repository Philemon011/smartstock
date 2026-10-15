"use client";

import { Menu } from "lucide-react";
import Link from "next/link";
import { useState, type ReactNode } from "react";
import { SidebarNav } from "@/components/app/sidebar-nav";
import { UserMenu } from "@/components/app/user-menu";
import { BrandLogo } from "@/components/brand/brand-logo";
import { useActiveMembership, useSession } from "@/components/session/session-provider";
import { ThemeToggle } from "@/components/theme-toggle";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { APP_NAME } from "@/lib/brand";

export function AppShell({ children }: { children: ReactNode }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const membership = useActiveMembership();
  const { signOutError, dismissSignOutError } = useSession();

  const readOnlyMessage = membership.tenant.read_only_message;

  return (
    <div className="min-h-dvh bg-background">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r bg-card lg:flex">
        <div className="flex h-16 items-center px-6">
          <Link href="/" aria-label="Accueil" className="rounded-md">
            <BrandLogo />
          </Link>
        </div>
        <div className="flex-1 overflow-y-auto px-4 py-4">
          <SidebarNav />
        </div>
      </aside>

      <div className="lg:pl-64">
        <header className="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b bg-card px-4 sm:px-6">
          <div className="flex min-w-0 items-center gap-3">
            <Button
              variant="ghost"
              size="sm"
              className="size-10 px-0 lg:hidden"
              aria-label="Ouvrir le menu"
              onClick={() => setMenuOpen(true)}
            >
              <Menu strokeWidth={1.5} />
            </Button>

            <Link href="/" aria-label="Accueil" className="rounded-md lg:hidden">
              <BrandLogo hideNameOnMobile />
            </Link>

            <div className="hidden min-w-0 items-center gap-2.5 lg:flex">
              <span className="truncate text-sm font-medium text-foreground">
                {membership.tenant.name}
              </span>
              <Badge>{membership.role_label}</Badge>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <ThemeToggle />
            <UserMenu />
          </div>
        </header>

        <main className="mx-auto w-full max-w-6xl space-y-6 px-4 py-8 sm:px-6 sm:py-10">
          {readOnlyMessage ? (
            <Alert variant="warning" title="Mode lecture seule">
              {readOnlyMessage}
            </Alert>
          ) : null}

          {signOutError ? (
            <Alert variant="danger" title="Déconnexion impossible">
              <p>{signOutError}</p>
              <Button variant="ghost" size="sm" className="-ml-3 mt-1" onClick={dismissSignOutError}>
                Fermer
              </Button>
            </Alert>
          ) : null}

          {children}
        </main>
      </div>

      <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
        <SheetContent side="left" title={APP_NAME} description={membership.tenant.name}>
          <div className="flex-1 overflow-y-auto px-4 py-4">
            <SidebarNav onNavigate={() => setMenuOpen(false)} />
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}