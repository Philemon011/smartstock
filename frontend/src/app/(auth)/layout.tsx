import type { ReactNode } from "react";
import { BrandPanel } from "@/components/auth/brand-panel";
import { BrandLogo } from "@/components/brand/brand-logo";
import { APP_NAME } from "@/lib/brand";

export default function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-dvh bg-background lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
      <div className="flex min-h-dvh flex-col bg-card px-6 py-8 sm:px-12 lg:px-16 xl:px-24">
        <header>
          <BrandLogo />
        </header>

        <main className="flex flex-1 items-center py-10">
          <div className="mx-auto w-full max-w-[26rem]">{children}</div>
        </main>

        <footer className="text-xs text-subtle-foreground">
          © {new Date().getFullYear()} {APP_NAME}. Tous droits réservés.
        </footer>
      </div>

      <aside className="hidden lg:block">
        <div className="sticky top-0 h-dvh p-4">
          <BrandPanel />
        </div>
      </aside>
    </div>
  );
}