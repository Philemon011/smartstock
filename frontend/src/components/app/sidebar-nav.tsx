"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useActiveMembership } from "@/components/session/session-provider";
import { useAlertSummary } from "@/hooks/use-alerts";
import { cn } from "@/lib/utils";
import { navGroups } from "./nav-items";

export function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const { abilities } = useActiveMembership();
  const summary = useAlertSummary();

  const alertsTotal = summary.data?.total ?? 0;
  const alertsUrgent = (summary.data?.expired ?? 0) + (summary.data?.out_of_stock ?? 0) > 0;

  return (
    <nav aria-label="Navigation principale" className="space-y-6">
      {navGroups.map((group) => {
        const items = group.items.filter((item) => !item.ability || abilities[item.ability]);

        if (items.length === 0) return null;

        return (
          <div key={group.label ?? "main"}>
            {group.label ? (
              <p className="mb-2 px-3 text-xs font-medium text-subtle-foreground">{group.label}</p>
            ) : null}

            <ul className="space-y-1">
              {items.map((item) => {
                const active = item.href === "/" ? pathname === "/" : pathname.startsWith(item.href);
                const count = item.badge === "alerts" ? alertsTotal : 0;

                return (
                  <li key={item.href}>
                    <Link
                      href={item.href}
                      onClick={onNavigate}
                      aria-current={active ? "page" : undefined}
                      className={cn(
                        "flex h-10 items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors duration-200 ease-smooth",
                        active
                          ? "bg-primary text-primary-foreground"
                          : "text-muted-foreground hover:bg-accent hover:text-foreground",
                      )}
                    >
                      <item.icon className="size-[18px]" strokeWidth={1.5} aria-hidden />
                      <span className="flex-1">{item.label}</span>

                      {count > 0 ? (
                        <span
                          className={cn(
                            "inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-xs font-medium tabular-nums",
                            active
                              ? "bg-white/20 text-primary-foreground"
                              : alertsUrgent
                                ? "bg-danger-soft text-danger"
                                : "bg-warning-soft text-warning",
                          )}
                        >
                          <span aria-hidden>{count > 99 ? "99+" : count}</span>
                          <span className="sr-only">
                            {count} {count > 1 ? "alertes" : "alerte"}
                          </span>
                        </span>
                      ) : null}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </nav>
  );
}